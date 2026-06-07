<?php
/**
 * Event Search Template
 * @var array $atts
 */
$theme_class = 'headcount-theme-' . esc_attr($atts['theme']);
?>

<div class="headcount-search-widget <?php echo $theme_class; ?>" x-data="headcountSearch()">
    <div class="headcount-search-form">
        <input 
            type="text" 
            x-model="searchQuery"
            @input.debounce.300ms="performSearch()"
            placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
            class="headcount-search-input"
        >
        <button type="button" @click="performSearch()" class="headcount-search-button">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/>
            </svg>
        </button>
    </div>
    
    <?php if ($atts['show_filters'] === 'true'): ?>
        <div class="headcount-search-filters">
            <button type="button" @click="clearFilters()" class="headcount-filter-clear">
                Clear Filters
            </button>
        </div>
    <?php endif; ?>
    
    <div x-show="loading" class="headcount-loading">
        Searching...
    </div>
    
    <div x-show="results.length > 0 && !loading" class="headcount-search-results">
        <template x-for="event in results" :key="event.id">
            <div class="headcount-search-result-item">
                <h4 x-text="event.title"></h4>
                <p x-text="event.formatted_date + ' at ' + event.formatted_time"></p>
            </div>
        </template>
    </div>
    
    <div x-show="searched && results.length === 0 && !loading" class="headcount-no-results">
        No events found matching your search.
    </div>
</div>

<script>
function headcountSearch() {
    return {
        searchQuery: '',
        results: [],
        loading: false,
        searched: false,
        
        performSearch() {
            if (this.searchQuery.length < 2) {
                this.results = [];
                this.searched = false;
                return;
            }
            
            this.loading = true;
            this.searched = true;
            
            // This would make an AJAX call to search endpoint
            // For now, it's a placeholder
            setTimeout(() => {
                this.results = [];
                this.loading = false;
            }, 500);
        },
        
        clearFilters() {
            this.searchQuery = '';
            this.results = [];
            this.searched = false;
        }
    }
}
</script>
