<?php
/**
 * Event Categories Filter Template
 * @var array $categories
 * @var array $atts
 */
$theme_class = 'headcount-theme-' . esc_attr($atts['theme']);
?>

<div class="headcount-categories-widget <?php echo $theme_class; ?>">
    <div class="headcount-categories-list">
        <?php if ($atts['show_all'] === 'true'): ?>
            <button type="button" class="headcount-category-button active" data-category="">
                All Events
            </button>
        <?php endif; ?>
        
        <?php foreach ($categories as $category): ?>
            <button type="button" class="headcount-category-button" data-category="<?php echo esc_attr($category); ?>">
                <?php echo esc_html($category); ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const categoryButtons = document.querySelectorAll('.headcount-category-button');
    
    categoryButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons
            categoryButtons.forEach(btn => btn.classList.remove('active'));
            
            // Add active class to clicked button
            this.classList.add('active');
            
            // Get category
            const category = this.dataset.category;
            
            // Trigger custom event for filtering
            document.dispatchEvent(new CustomEvent('headcount-filter-category', {
                detail: { category: category }
            }));
        });
    });
});
</script>
