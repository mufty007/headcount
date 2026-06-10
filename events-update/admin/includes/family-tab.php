<!-- Family Relationships Tab Content -->
<!-- Add this to the member details modal/page -->

<div id="family-tab-content" style="display: none;">
    <div class="p-6">
        <!-- Existing Relationships -->
        <div class="mb-8">
            <h3 class="mb-4 text-lg font-bold text-gray-900 dark:text-white">Family Relationships</h3>
            <div id="family-relationships-list" class="space-y-3">
                <!-- Relationships will be loaded here via JavaScript -->
                <div class="text-center py-4">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Add New Relationship Form -->
        <div class="border-t border-gray-200 pt-6 dark:border-gray-700">
            <h4 class="text-md mb-4 flex items-center gap-2 font-bold text-gray-900 dark:text-white">
                <svg class="h-5 w-5 text-brand-600 dark:text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Add Family Member
            </h4>
            
            <div class="space-y-4">
                <!-- Member Search/Select -->
                <div>
                    <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                        Select Family Member
                    </label>
                    <select 
                        id="related-member-select" 
                        class="w-full rounded-xl border border-gray-200 px-4 py-3 font-medium outline-none transition-all focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <option value="">-- Select a member --</option>
                        <!-- Options will be populated via JavaScript from members list -->
                    </select>
                </div>

                <!-- Relationship Type -->
                <div>
                    <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                        Relationship Type
                    </label>
                    <select 
                        id="relationship-type-select"
                        class="w-full rounded-xl border border-gray-200 px-4 py-3 font-medium outline-none transition-all focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <option value="">-- Select relationship --</option>
                        <option value="spouse">💑 Spouse (Husband/Wife/Partner)</option>
                        <option value="parent">👨‍👩‍👦 Parent</option>
                        <option value="child">👶 Child</option>
                        <option value="sibling">👫 Sibling (Brother/Sister)</option>
                        <option value="guardian">🛡️ Guardian</option>
                        <option value="ward">👤 Ward</option>
                        <option value="other">👥 Other</option>
                    </select>
                </div>

                <!-- Optional Notes -->
                <div>
                    <label class="mb-2 block text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                        Notes (Optional)
                    </label>
                    <input 
                        type="text" 
                        id="relationship-notes"
                        placeholder="e.g., Emergency contact, Lives together, etc."
                        class="w-full rounded-xl border border-gray-200 px-4 py-3 font-medium outline-none transition-all focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                </div>

                <!-- Add Button -->
                <button 
                    onclick="FamilyRelationships.handleAddRelationship(currentMemberId)"
                    class="w-full bg-brand-600 hover:bg-brand-700 text-white font-semibold py-3 px-6 rounded-xl transition-colors flex items-center justify-center gap-2"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Add Relationship
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize family relationships when member details are opened
function initializeFamilyTab(memberId) {
    window.currentMemberId = memberId;
    
    // Load relationships
    FamilyRelationships.loadAndDisplayRelationships(memberId);
    
    // Populate member select dropdown (exclude current member)
    populateMemberSelect(memberId);
}

// Populate the member select dropdown
async function populateMemberSelect(currentMemberId) {
    const select = document.getElementById('related-member-select');
    if (!select) return;
    
    try {
        // Get all members from the current page data or fetch from API
        const members = window.allMembers || await fetchAllMembers();
        
        select.innerHTML = '<option value="">-- Select a member --</option>';
        
        members
            .filter(m => m.id != currentMemberId) // Exclude current member
            .forEach(member => {
                const option = document.createElement('option');
                option.value = member.id;
                option.textContent = `${member.first_name} ${member.last_name}`;
                select.appendChild(option);
            });
    } catch (error) {
        console.error('Error populating member select:', error);
    }
}

// Fetch all members if not already available
async function fetchAllMembers() {
    try {
        const response = await fetch('<?= $basePath ?>/api/members');
        const result = await response.json();
        return result.data || [];
    } catch (error) {
        console.error('Error fetching members:', error);
        return [];
    }
}
</script>

<!-- Include the family relationships JavaScript -->
<script src="<?= $basePath ?>/assets/js/family-relationships.js"></script>
