/**
 * Family Relationships Management
 * Handles UI and API calls for member family relationships
 */

// Family Relationships functionality
window.FamilyRelationships = {
    // Load relationships for a member
    async loadRelationships(memberId) {
        try {
            const response = await fetch(`<?= $basePath ?>/api/members/${memberId}/relationships`, {
                headers: {
                    'Content-Type': 'application/json'
                }
            });

            const result = await response.json();

            if (result.success) {
                return result.data || [];
            } else {
                console.error('Failed to load relationships:', result.message);
                return [];
            }
        } catch (error) {
            console.error('Error loading relationships:', error);
            return [];
        }
    },

    // Add a new relationship
    async addRelationship(memberId, relatedMemberId, relationshipType, notes = '') {
        try {
            const response = await fetch(`<?= $basePath ?>/api/members/${memberId}/relationships`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify({
                    related_member_id: relatedMemberId,
                    relationship_type: relationshipType,
                    notes: notes
                })
            });

            const result = await response.json();

            if (result.success) {
                showNotification('Relationship added successfully', 'success');
                return true;
            } else {
                showNotification(result.message || 'Failed to add relationship', 'error');
                return false;
            }
        } catch (error) {
            console.error('Error adding relationship:', error);
            showNotification('Error adding relationship', 'error');
            return false;
        }
    },

    // Delete a relationship
    async deleteRelationship(memberId, relatedMemberId) {
        try {
            const response = await fetch(`<?= $basePath ?>/api/members/${memberId}/relationships/${relatedMemberId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                }
            });

            const result = await response.json();

            if (result.success) {
                showNotification('Relationship removed successfully', 'success');
                return true;
            } else {
                showNotification(result.message || 'Failed to remove relationship', 'error');
                return false;
            }
        } catch (error) {
            console.error('Error deleting relationship:', error);
            showNotification('Error removing relationship', 'error');
            return false;
        }
    },

    // Render relationships list
    renderRelationshipsList(relationships, memberId) {
        if (!relationships || relationships.length === 0) {
            return `
                <div class="text-center py-8 text-gray-400">
                    <svg class="w-16 h-16 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <p class="font-medium">No family relationships yet</p>
                    <p class="text-sm mt-1">Add family connections below</p>
                </div>
            `;
        }

        const relationshipIcons = {
            'spouse': '💑',
            'parent': '👨‍👩‍👦',
            'child': '👶',
            'sibling': '👫',
            'guardian': '🛡️',
            'ward': '👤',
            'other': '👥'
        };

        return relationships.map(rel => `
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">${relationshipIcons[rel.relationship_type] || '👥'}</span>
                    <div>
                        <p class="font-semibold text-gray-900">
                            ${escapeHtml(rel.related_first_name)} ${escapeHtml(rel.related_last_name)}
                        </p>
                        <p class="text-sm text-gray-500 capitalize">${rel.relationship_type}</p>
                        ${rel.notes ? `<p class="text-xs text-gray-400 mt-1">${escapeHtml(rel.notes)}</p>` : ''}
                    </div>
                </div>
                <button 
                    onclick="FamilyRelationships.confirmDeleteRelationship(${memberId}, ${rel.related_member_id}, '${escapeHtml(rel.related_first_name)} ${escapeHtml(rel.related_last_name)}')"
                    class="text-red-500 hover:text-red-700 hover:bg-red-50 p-2 rounded-lg transition-colors"
                    title="Remove relationship"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            </div>
        `).join('');
    },

    // Confirm delete relationship
    async confirmDeleteRelationship(memberId, relatedMemberId, relatedName) {
        if (confirm(`Are you sure you want to remove the family relationship with ${relatedName}?`)) {
            const success = await this.deleteRelationship(memberId, relatedMemberId);
            if (success) {
                // Reload relationships
                await this.loadAndDisplayRelationships(memberId);
            }
        }
    },

    // Load and display relationships
    async loadAndDisplayRelationships(memberId) {
        const container = document.getElementById('family-relationships-list');
        if (!container) return;

        container.innerHTML = '<div class="text-center py-4"><div class="spinner"></div></div>';

        const relationships = await this.loadRelationships(memberId);
        container.innerHTML = this.renderRelationshipsList(relationships, memberId);
    },

    // Show add relationship form
    showAddRelationshipForm(memberId) {
        // This will be called when the "Add Family Member" button is clicked
        // The form is already in the HTML, we just need to handle the submission
        const form = document.getElementById('add-relationship-form');
        if (form) {
            form.style.display = 'block';
        }
    },

    // Handle add relationship form submission
    async handleAddRelationship(memberId) {
        const relatedMemberSelect = document.getElementById('related-member-select');
        const relationshipTypeSelect = document.getElementById('relationship-type-select');
        const notesInput = document.getElementById('relationship-notes');

        const relatedMemberId = relatedMemberSelect.value;
        const relationshipType = relationshipTypeSelect.value;
        const notes = notesInput.value;

        if (!relatedMemberId || !relationshipType) {
            showNotification('Please select a family member and relationship type', 'error');
            return;
        }

        const success = await this.addRelationship(memberId, relatedMemberId, relationshipType, notes);

        if (success) {
            // Clear form
            relatedMemberSelect.value = '';
            relationshipTypeSelect.value = '';
            notesInput.value = '';

            // Reload relationships
            await this.loadAndDisplayRelationships(memberId);
        }
    }
};

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to get CSRF token
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// Helper function to show notifications
function showNotification(message, type = 'info') {
    // Use existing notification system if available, otherwise use alert
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
    } else {
        alert(message);
    }
}
