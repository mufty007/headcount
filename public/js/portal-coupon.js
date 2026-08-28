/**
 * Validate org coupons on paid event / program / facility booking forms.
 */
(function (global) {
    var pendingClass = 'text-xs text-gray-500 dark:text-gray-400 min-h-[1rem]';
    var okClass = 'text-xs text-green-700 dark:text-green-300 min-h-[1rem]';
    var errClass = 'text-xs text-red-600 dark:text-red-300 min-h-[1rem]';

    function apiUrl(baseUrl) {
        var root = String(baseUrl || '').replace(/\/+$/, '');
        return root + '/api/portal/coupons.php';
    }

    async function validate(opts) {
        opts = opts || {};
        var code = String(opts.code || '').trim();
        if (!code) {
            return { success: true, valid: false, empty: true, message: '' };
        }
        var url = apiUrl(opts.baseUrl)
            + '?action=validate'
            + '&type=' + encodeURIComponent(opts.type || '')
            + '&id=' + encodeURIComponent(String(opts.id || ''))
            + '&code=' + encodeURIComponent(code);
        var res = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        var json = {};
        try {
            json = await res.json();
        } catch (e) {
            json = {};
        }
        if (!json || typeof json !== 'object') {
            return { success: false, valid: false, message: 'Could not check coupon.' };
        }
        return json;
    }

    function applyDiscount(total, meta) {
        var n = Number(total);
        if (!(n > 0) || !meta) {
            return n;
        }
        if (meta.percent_off) {
            return Math.round(n * (1 - Number(meta.percent_off) / 100) * 100) / 100;
        }
        if (meta.amount_off) {
            return Math.max(0, Math.round((n - Number(meta.amount_off)) * 100) / 100);
        }
        return n;
    }

    function setStatus(el, text, className) {
        if (!el) {
            return;
        }
        el.textContent = text || '';
        if (className) {
            el.className = className;
        }
    }

    function bindField(opts) {
        opts = opts || {};
        var input = opts.input;
        var button = opts.button;
        var status = opts.status;
        if (!input || !button) {
            return;
        }
        var run = async function () {
            button.disabled = true;
            setStatus(status, 'Checking…', pendingClass);
            var result;
            try {
                result = await validate({
                    baseUrl: opts.baseUrl,
                    type: opts.type,
                    id: opts.id,
                    code: input.value
                });
            } catch (e) {
                result = { success: false, valid: false, message: 'Could not check coupon.' };
            }
            button.disabled = false;
            if (result.empty) {
                setStatus(status, 'Enter a coupon code', errClass);
            } else if (result.valid) {
                setStatus(status, result.message || 'Coupon applied', okClass);
            } else {
                setStatus(status, result.message || 'Invalid code', errClass);
            }
            if (typeof opts.onResult === 'function') {
                opts.onResult(result);
            }
            return result;
        };
        button.addEventListener('click', function (e) {
            e.preventDefault();
            run();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                run();
            }
        });
        input.addEventListener('input', function () {
            setStatus(status, '', pendingClass);
            if (typeof opts.onChange === 'function') {
                opts.onChange();
            }
        });
        return run;
    }

    async function ensureValid(opts) {
        var code = String(opts.code || '').trim();
        if (!code) {
            return { ok: true, result: { empty: true, valid: false } };
        }
        var result;
        try {
            result = await validate(opts);
        } catch (e) {
            return { ok: false, result: { valid: false, message: 'Could not check coupon.' } };
        }
        if (result.valid) {
            return { ok: true, result: result };
        }
        return { ok: false, result: result };
    }

    async function applyAlpine(vm, opts) {
        opts = opts || {};
        vm.couponBusy = true;
        vm.couponMsg = '';
        vm.couponOk = false;
        vm.couponMeta = null;
        var code = opts.code != null
            ? opts.code
            : (vm.form && vm.form.coupon_code != null ? vm.form.coupon_code : vm.coupon);
        try {
            var result = await validate({
                baseUrl: opts.baseUrl || vm.baseUrl || vm.couponApiBase,
                type: opts.type,
                id: opts.id,
                code: code
            });
            if (result.empty) {
                vm.couponMsg = 'Enter a coupon code';
                vm.couponOk = false;
            } else if (result.valid) {
                vm.couponOk = true;
                vm.couponMeta = result;
                vm.couponMsg = result.message || 'Coupon applied';
            } else {
                vm.couponMsg = result.message || 'Invalid code';
            }
            return result;
        } catch (e) {
            vm.couponMsg = 'Could not check coupon.';
            return { valid: false, message: vm.couponMsg };
        } finally {
            vm.couponBusy = false;
        }
    }

    async function ensureAlpine(vm, opts) {
        var code = opts.code != null
            ? opts.code
            : (vm.form && vm.form.coupon_code != null ? vm.form.coupon_code : vm.coupon);
        code = String(code || '').trim();
        if (!code) {
            vm.couponOk = false;
            vm.couponMeta = null;
            vm.couponMsg = '';
            return true;
        }
        if (vm.couponOk && vm.couponMeta && String(vm.couponMeta.code || '').toUpperCase() === code.toUpperCase()) {
            return true;
        }
        var result = await applyAlpine(vm, opts);
        return !!(result && result.valid);
    }

    global.headcountCoupon = {
        validate: validate,
        applyDiscount: applyDiscount,
        bindField: bindField,
        ensureValid: ensureValid,
        applyAlpine: applyAlpine,
        ensureAlpine: ensureAlpine
    };
})(window);
