/**
 * Headcount: shared Quill 1.3 toolbar extensions (image upload or URL, video upload or YouTube/Vimeo, emoji picker).
 */
(function (global) {
    'use strict';

    var emojiDocHideBound = false;
    var videoPanelDocHideBound = false;
    var imagePanelDocHideBound = false;
    /** @type {{ quill: Quill, videoInput: HTMLInputElement, uploadVideoUrl: string, csrfToken: string } | null} */
    var videoInsertContext = null;
    /** @type {{ quill: Quill, imageInput: HTMLInputElement, uploadImageUrl: string, csrfToken: string } | null} */
    var imageInsertContext = null;

    var EMOJI_GROUPS = [
        { label: 'Smileys', chars: ['😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊', '😇', '🙂', '😉', '😍', '🥰', '😘', '😋', '😛', '🤔', '😏', '😌', '🙃'] },
        { label: 'Gestures', chars: ['👍', '👎', '👌', '✌️', '🤞', '🤝', '👏', '🙌', '👐', '🤲', '🙏', '💪', '✋', '🖐️', '👋', '🤚', '☝️', '👆', '👇', '✍️'] },
        { label: 'Hearts', chars: ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '♥️', '💟', '❣️', '💌', '💋'] },
        { label: 'Objects', chars: ['⭐', '🎉', '🎁', '📧', '📅', '⏰', '📍', '🔗', '✉️', '📣', '🔔', '💡', '📌', '📎', '✅', '❌', '🔥', '✨', '💼', '🏠'] }
    ];

    function parseYouTubeId(url) {
        if (!url || typeof url !== 'string') return null;
        var m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        return m ? m[1] : null;
    }

    function parseVimeoId(url) {
        if (!url || typeof url !== 'string') return null;
        var m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        return m ? m[1] : null;
    }

    function escapeAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function insertVideoHtml(quill, videoUrl) {
        videoUrl = (videoUrl || '').trim();
        if (!videoUrl) return;
        var yt = parseYouTubeId(videoUrl);
        var vm = parseVimeoId(videoUrl);
        var thumb;
        if (yt) {
            thumb = 'https://img.youtube.com/vi/' + yt + '/hqdefault.jpg';
        } else if (vm) {
            thumb = 'https://vumbnail.com/' + vm + '.jpg';
        } else {
            thumb = 'data:image/svg+xml,' + encodeURIComponent(
                '<svg xmlns="http://www.w3.org/2000/svg" width="560" height="315" viewBox="0 0 560 315"><rect fill="#1e293b" width="560" height="315"/><text x="50%" y="50%" fill="#fff" font-family="sans-serif" font-size="18" text-anchor="middle" dy=".3em">Watch video</text></svg>'
            );
        }
        var safeUrl = escapeAttr(videoUrl);
        var safeThumb = escapeAttr(thumb);
        var block =
            '<p><a class="video-preview" href="' + safeUrl + '" target="_blank" rel="noopener noreferrer" style="display:block;position:relative;text-decoration:none;max-width:560px;">' +
            '<span style="display:block;position:relative;">' +
            '<img src="' + safeThumb + '" alt="Video" width="560" style="max-width:100%;height:auto;display:block;border-radius:4px;vertical-align:middle;" />' +
            '<span style="position:absolute;left:50%;top:50%;width:64px;height:64px;margin-left:-32px;margin-top:-32px;background:rgba(0,0,0,0.55);border-radius:50%;display:flex;align-items:center;justify-content:center;">' +
            '<span style="width:0;height:0;border-style:solid;border-width:12px 0 12px 20px;border-color:transparent transparent transparent #fff;margin-left:4px;"></span>' +
            '</span></span></a></p>';
        var range = quill.getSelection(true);
        var index = range ? range.index : quill.getLength();
        quill.clipboard.dangerouslyPasteHTML(index, block);
        quill.setSelection(index + 1);
    }

    /** Uploaded file (MP4/WebM) — HTML5 video; many inboxes prefer links, but preview works in browser. */
    function insertUploadedVideoHtml(quill, fileUrl) {
        fileUrl = (fileUrl || '').trim();
        if (!fileUrl) return;
        var safe = escapeAttr(fileUrl);
        var block =
            '<p><video src="' + safe + '" controls preload="metadata" playsinline style="max-width:100%;width:560px;height:auto;display:block;border-radius:4px;background:#0f172a;"></video></p>';
        var range = quill.getSelection(true);
        var index = range ? range.index : quill.getLength();
        quill.clipboard.dangerouslyPasteHTML(index, block);
        quill.setSelection(index + 1);
    }

    function uploadFileToUrl(endpoint, fieldName, file, csrfToken, cb) {
        if (!endpoint || !file || !csrfToken) {
            cb(new Error('Missing upload options'));
            return;
        }
        var fd = new FormData();
        fd.append(fieldName, file);
        fd.append('csrf_token', csrfToken);
        fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success && data.url) {
                    cb(null, data.url);
                } else {
                    cb(new Error((data && data.message) || 'Upload failed'));
                }
            })
            .catch(function () {
                cb(new Error('Upload failed'));
            });
    }

    function ensureEmojiPanel(quill, toolbarContainer) {
        var existing = document.getElementById('hc-quill-emoji-panel');
        if (existing) return existing;
        var panel = document.createElement('div');
        panel.id = 'hc-quill-emoji-panel';
        panel.setAttribute('role', 'listbox');
        panel.style.cssText = 'display:none;position:absolute;z-index:10050;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(15,23,42,0.12);padding:12px;max-width:280px;max-height:240px;overflow-y:auto;';
        EMOJI_GROUPS.forEach(function (g) {
            var lab = document.createElement('div');
            lab.style.cssText = 'font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;margin:8px 0 6px;';
            lab.textContent = g.label;
            panel.appendChild(lab);
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;flex-wrap:wrap;gap:4px;';
            g.chars.forEach(function (ch) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'hc-emoji-cell';
                b.style.cssText = 'font-size:20px;line-height:1;padding:6px 8px;border:none;background:#f8fafc;border-radius:8px;cursor:pointer;';
                b.textContent = ch;
                b.setAttribute('aria-label', 'Insert emoji');
                row.appendChild(b);
            });
            panel.appendChild(row);
        });
        panel.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.hc-emoji-cell');
            if (!btn) return;
            var ch = btn.textContent;
            if (!ch) return;
            var range = quill.getSelection(true);
            var index = range ? range.index : quill.getLength();
            quill.insertText(index, ch, 'user');
            quill.setSelection(index + ch.length);
            panel.style.display = 'none';
        });
        document.body.appendChild(panel);
        return panel;
    }

    function ensureImageInsertPanel() {
        var panel = document.getElementById('hc-quill-image-panel');
        if (panel) {
            return panel;
        }
        panel = document.createElement('div');
        panel.id = 'hc-quill-image-panel';
        panel.setAttribute('role', 'menu');
        panel.style.cssText =
            'display:none;position:absolute;z-index:10050;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(15,23,42,0.12);padding:8px;min-width:240px;font-size:13px;';
        var btnUp = document.createElement('button');
        btnUp.type = 'button';
        btnUp.className = 'hc-image-upload';
        btnUp.textContent = 'Upload image…';
        btnUp.style.cssText =
            'display:block;width:100%;text-align:left;padding:10px 12px;border:none;border-radius:8px;background:#f8fafc;font-weight:700;font-size:13px;color:#0f172a;cursor:pointer;margin-bottom:6px;';
        var btnLink = document.createElement('button');
        btnLink.type = 'button';
        btnLink.className = 'hc-image-link';
        btnLink.textContent = 'Paste image URL…';
        btnLink.style.cssText =
            'display:block;width:100%;text-align:left;padding:10px 12px;border:none;border-radius:8px;background:#eef2ff;font-weight:700;font-size:13px;color:#4338ca;cursor:pointer;';
        btnUp.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.style.display = 'none';
            var ctx = imageInsertContext;
            if (ctx && ctx.imageInput && ctx.uploadImageUrl && ctx.csrfToken) {
                ctx.imageInput.click();
            }
        });
        btnLink.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.style.display = 'none';
            var ctx = imageInsertContext;
            if (!ctx || !ctx.quill) {
                return;
            }
            var url = window.prompt('Image URL');
            url = (url || '').trim();
            if (!url) {
                return;
            }
            var range = ctx.quill.getSelection(true);
            var index = range ? range.index : ctx.quill.getLength();
            var safe = escapeAttr(url);
            var img = '<img src="' + safe + '" alt="" style="max-width:100%;height:auto;border-radius:4px;display:block;" />';
            ctx.quill.clipboard.dangerouslyPasteHTML(index, img);
            ctx.quill.setSelection(index + 1);
        });
        panel.appendChild(btnUp);
        panel.appendChild(btnLink);
        document.body.appendChild(panel);
        return panel;
    }

    function ensureVideoInsertPanel() {
        var panel = document.getElementById('hc-quill-video-panel');
        if (panel) {
            return panel;
        }
        panel = document.createElement('div');
        panel.id = 'hc-quill-video-panel';
        panel.setAttribute('role', 'menu');
        panel.style.cssText =
            'display:none;position:absolute;z-index:10050;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(15,23,42,0.12);padding:8px;min-width:240px;font-size:13px;';
        var btnUp = document.createElement('button');
        btnUp.type = 'button';
        btnUp.className = 'hc-video-upload w-full text-left px-3 py-2.5 rounded-lg font-bold text-slate-800 hover:bg-slate-50 border border-transparent';
        btnUp.textContent = 'Upload video file…';
        btnUp.style.cssText =
            'display:block;width:100%;text-align:left;padding:10px 12px;border:none;border-radius:8px;background:#f8fafc;font-weight:700;font-size:13px;color:#0f172a;cursor:pointer;margin-bottom:6px;';
        var btnLink = document.createElement('button');
        btnLink.type = 'button';
        btnLink.className = 'hc-video-link w-full text-left px-3 py-2.5 rounded-lg font-bold text-indigo-700 hover:bg-indigo-50';
        btnLink.textContent = 'YouTube / Vimeo link…';
        btnLink.style.cssText =
            'display:block;width:100%;text-align:left;padding:10px 12px;border:none;border-radius:8px;background:#eef2ff;font-weight:700;font-size:13px;color:#4338ca;cursor:pointer;';
        btnUp.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.style.display = 'none';
            var ctx = videoInsertContext;
            if (ctx && ctx.videoInput && ctx.uploadVideoUrl && ctx.csrfToken) {
                ctx.videoInput.click();
            } else {
                window.alert('Video upload is not available. Use the link option or contact an administrator.');
            }
        });
        btnLink.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.style.display = 'none';
            var ctx = videoInsertContext;
            if (!ctx || !ctx.quill) {
                return;
            }
            var url = window.prompt('YouTube or Vimeo URL');
            insertVideoHtml(ctx.quill, url);
        });
        panel.appendChild(btnUp);
        panel.appendChild(btnLink);
        document.body.appendChild(panel);
        return panel;
    }

    function positionPanel(panel, anchorEl) {
        var r = anchorEl.getBoundingClientRect();
        panel.style.display = 'block';
        var pw = panel.offsetWidth || 280;
        var left = r.left + window.scrollX;
        var top = r.bottom + window.scrollY + 4;
        if (left + pw > window.innerWidth + window.scrollX - 8) {
            left = window.innerWidth + window.scrollX - pw - 8;
        }
        panel.style.left = Math.max(8, left) + 'px';
        panel.style.top = top + 'px';
    }

    /**
     * @param {Quill} quill
     * @param {object} [opts]
     */
    function headcountInitQuillRichToolbar(quill, opts) {
        if (!quill || !quill.getModule) return;
        opts = opts || {};
        var uploadImageUrl = opts.uploadImageUrl || '';
        var uploadVideoUrl = opts.uploadVideoUrl || '';
        var csrfToken = opts.csrfToken || '';
        var toolbar = quill.getModule('toolbar');
        if (!toolbar || !toolbar.container) return;

        var imageInput = document.createElement('input');
        imageInput.type = 'file';
        imageInput.accept = 'image/*';
        imageInput.setAttribute('aria-hidden', 'true');
        imageInput.style.cssText = 'position:fixed;left:-9999px;width:1px;height:1px;opacity:0;';
        document.body.appendChild(imageInput);
        imageInput.addEventListener('change', function () {
            var file = imageInput.files && imageInput.files[0];
            imageInput.value = '';
            if (!file) {
                return;
            }
            if (uploadImageUrl && csrfToken) {
                uploadFileToUrl(uploadImageUrl, 'image', file, csrfToken, function (err, url) {
                    if (err) {
                        window.alert(err.message);
                        return;
                    }
                    var range = quill.getSelection(true);
                    var index = range ? range.index : quill.getLength();
                    var safe = escapeAttr(url);
                    var img = '<img src="' + safe + '" alt="" style="max-width:100%;height:auto;border-radius:4px;display:block;" />';
                    quill.clipboard.dangerouslyPasteHTML(index, img);
                    quill.setSelection(index + 1);
                });
                return;
            }
            window.alert('Image upload is not configured.');
        });

        toolbar.addHandler('image', function () {
            if (!uploadImageUrl || !csrfToken) {
                var urlFallback = window.prompt('Image URL');
                urlFallback = (urlFallback || '').trim();
                if (!urlFallback) {
                    return;
                }
                var range0 = quill.getSelection(true);
                var index0 = range0 ? range0.index : quill.getLength();
                var safe0 = escapeAttr(urlFallback);
                var img0 = '<img src="' + safe0 + '" alt="" style="max-width:100%;height:auto;border-radius:4px;display:block;" />';
                quill.clipboard.dangerouslyPasteHTML(index0, img0);
                quill.setSelection(index0 + 1);
                return;
            }
            imageInsertContext = {
                quill: quill,
                imageInput: imageInput,
                uploadImageUrl: uploadImageUrl,
                csrfToken: csrfToken
            };
            var imgPanel = ensureImageInsertPanel();
            var openImg = imgPanel.style.display === 'block';
            if (openImg) {
                imgPanel.style.display = 'none';
                return;
            }
            positionPanel(imgPanel, toolbar.container.querySelector('.ql-image') || toolbar.container);
        });

        if (!imagePanelDocHideBound) {
            imagePanelDocHideBound = true;
            document.addEventListener('click', function (ev) {
                var panel = document.getElementById('hc-quill-image-panel');
                if (!panel || panel.style.display !== 'block') {
                    return;
                }
                if (panel.contains(ev.target)) {
                    return;
                }
                var t = ev.target;
                if (t && t.closest && t.closest('.ql-image')) {
                    return;
                }
                panel.style.display = 'none';
            });
        }

        var videoInput = document.createElement('input');
        videoInput.type = 'file';
        videoInput.accept = 'video/mp4,video/webm,video/ogg,.mp4,.webm,.mov,.ogv';
        videoInput.setAttribute('aria-hidden', 'true');
        videoInput.style.cssText = 'position:fixed;left:-9999px;width:1px;height:1px;opacity:0;';
        document.body.appendChild(videoInput);
        videoInput.addEventListener('change', function () {
            var file = videoInput.files && videoInput.files[0];
            videoInput.value = '';
            if (!file || !uploadVideoUrl || !csrfToken) {
                return;
            }
            uploadFileToUrl(uploadVideoUrl, 'video', file, csrfToken, function (err, url) {
                if (err) {
                    window.alert(err.message);
                    return;
                }
                insertUploadedVideoHtml(quill, url);
            });
        });

        var container = toolbar.container;
        var fmt = document.createElement('span');
        fmt.className = 'ql-formats';
        fmt.style.cssText = 'margin-left:4px;';

        var videoBtn = document.createElement('button');
        videoBtn.type = 'button';
        videoBtn.className = 'ql-hc-video';
        videoBtn.title = uploadVideoUrl && csrfToken ? 'Insert video (upload or link)' : 'Insert video (YouTube / Vimeo)';
        videoBtn.setAttribute('aria-label', 'Insert video');
        videoBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        videoBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            if (!uploadVideoUrl || !csrfToken) {
                var urlOnly = window.prompt('YouTube or Vimeo URL');
                insertVideoHtml(quill, urlOnly);
                return;
            }
            videoInsertContext = {
                quill: quill,
                videoInput: videoInput,
                uploadVideoUrl: uploadVideoUrl,
                csrfToken: csrfToken
            };
            var panel = ensureVideoInsertPanel();
            var btnUp = panel.querySelector('.hc-video-upload');
            if (btnUp) {
                btnUp.style.display = 'block';
            }
            var open = panel.style.display === 'block';
            if (open) {
                panel.style.display = 'none';
                return;
            }
            positionPanel(panel, videoBtn);
        });

        if (!videoPanelDocHideBound) {
            videoPanelDocHideBound = true;
            document.addEventListener('click', function (ev) {
                var panel = document.getElementById('hc-quill-video-panel');
                if (!panel || panel.style.display !== 'block') {
                    return;
                }
                if (panel.contains(ev.target)) {
                    return;
                }
                var t = ev.target;
                if (t && t.closest && t.closest('.ql-hc-video')) {
                    return;
                }
                panel.style.display = 'none';
            });
        }

        var emojiBtn = document.createElement('button');
        emojiBtn.type = 'button';
        emojiBtn.className = 'ql-hc-emoji';
        emojiBtn.title = 'Emoji';
        emojiBtn.setAttribute('aria-label', 'Insert emoji');
        emojiBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>';

        var emojiPanel = ensureEmojiPanel(quill, container);
        emojiBtn.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var open = emojiPanel.style.display === 'block';
            if (open) {
                emojiPanel.style.display = 'none';
                return;
            }
            positionPanel(emojiPanel, emojiBtn);
        });

        if (!emojiDocHideBound) {
            emojiDocHideBound = true;
            document.addEventListener('click', function (ev) {
                var panel = document.getElementById('hc-quill-emoji-panel');
                if (!panel || panel.style.display !== 'block') return;
                if (panel.contains(ev.target)) return;
                var t = ev.target;
                if (t && t.closest && t.closest('.ql-hc-emoji')) return;
                panel.style.display = 'none';
            });
        }

        fmt.appendChild(videoBtn);
        fmt.appendChild(emojiBtn);
        container.appendChild(fmt);
    }

    function extractBodyFromCampaignHtml(stored) {
        var s = (stored || '').trim();
        if (!s) return '';
        if (/^\s*<!DOCTYPE/i.test(s) || /^\s*<html[\s>]/i.test(s)) {
            try {
                var doc = new DOMParser().parseFromString(s, 'text/html');
                if (doc && doc.body) return doc.body.innerHTML;
            } catch (e) { /* ignore */ }
        }
        return s;
    }

    function buildCampaignPreviewHtml(fragment, orgName, logoUrl) {
        orgName = orgName || 'Organization';
        var logoBlock = '';
        if (logoUrl) {
            logoBlock = '<div style="text-align:center;padding-bottom:16px;border-bottom:1px solid #e2e8f0;margin-bottom:24px;">' +
                '<img src="' + escapeAttr(logoUrl) + '" alt="" style="max-height:56px;max-width:200px;display:inline-block;" />' +
                '</div>';
        }
        var header = '<div style="font-size:20px;font-weight:700;color:#0f172a;text-align:center;padding:8px 0 20px;">' + escapeAttr(orgName) + '</div>';
        var footer = '<div style="margin-top:32px;padding-top:20px;border-top:1px solid #e2e8f0;font-size:13px;color:#64748b;text-align:center;">' + escapeAttr(orgName) + '</div>';
        var body = fragment || '';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
            '<style>body{margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased;}' +
            '.email-shell{max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.08);overflow:hidden;}' +
            '.email-pad{padding:32px 28px;}' +
            '.body{font-size:15px;line-height:1.6;color:#1e293b;}' +
            '.body img{max-width:100%;height:auto;border-radius:4px;}' +
            '.body video{max-width:100%;height:auto;border-radius:4px;}' +
            '.body .video-preview{display:block;position:relative;text-decoration:none;}' +
            '</style></head><body><div class="email-shell"><div class="email-pad">' +
            logoBlock + header +
            '<div class="body">' + body + '</div>' + footer +
            '</div></div></body></html>';
    }

    global.headcountInitQuillRichToolbar = headcountInitQuillRichToolbar;
    global.headcountExtractBodyFromCampaignHtml = extractBodyFromCampaignHtml;
    global.headcountBuildCampaignPreviewHtml = buildCampaignPreviewHtml;
})(typeof window !== 'undefined' ? window : this);
