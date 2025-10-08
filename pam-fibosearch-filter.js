/**
 * Product Access Manager - FiboSearch Client-Side Filtering
 * Version: 2.0.4
 * 
 * Filters FiboSearch results on the client-side because FiboSearch uses SHORTINIT mode
 * which bypasses our server-side PHP filters.
 */

(function($) {
    'use strict';
    
    let restrictedProducts = [];
    let restrictedBrands = [];
    
    console.log('[PAM FiboSearch] Script loaded, AJAX URL:', pamFiboFilter.ajaxUrl);
    
    // Fetch restricted products for current user
    $.ajax({
        url: pamFiboFilter.ajaxUrl,
        method: 'POST',
        data: {
            action: 'pam_get_restricted_data'
        },
        success: function(response) {
            console.log('[PAM FiboSearch] AJAX response:', response);
            if (response.success) {
                restrictedProducts = response.data.products || [];
                restrictedBrands = response.data.brands || [];
                console.log('[PAM FiboSearch] Restricted products:', restrictedProducts.length);
                console.log('[PAM FiboSearch] Restricted brands:', restrictedBrands);
            }
        },
        error: function(xhr, status, error) {
            console.error('[PAM FiboSearch] AJAX error:', error, xhr.responseText);
        }
    });
    
    // Debounce timer to prevent excessive filtering
    let filterTimer = null;
    
    // Watch for FiboSearch results
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                // Clear existing timer
                if (filterTimer) {
                    clearTimeout(filterTimer);
                }
                
                // Filter immediately
                filterResults();
                
                // Also filter after a delay (in case more content loads)
                filterTimer = setTimeout(function() {
                    console.log('[PAM FiboSearch] Running delayed filter...');
                    filterResults();
                }, 300);
            }
        });
    });
    
    // Start observing
    const target = document.querySelector('body');
    if (target) {
        observer.observe(target, {
            childList: true,
            subtree: true
        });
    }
    
    function filterResults() {
        const wrapper = $('.dgwt-wcas-suggestions-wrapp');
        if (!wrapper.length || !wrapper.is(':visible')) {
            return;
        }
        
        console.log('[PAM FiboSearch] Filtering results...');
        console.log('[PAM FiboSearch] Restricted products count:', restrictedProducts.length);
        console.log('[PAM FiboSearch] Restricted brands:', restrictedBrands);
        
        let productsFiltered = 0;
        let brandsFiltered = 0;
        
        // Debug: Log what elements we find
        const productElements = $('.dgwt-wcas-suggestion-product, .dgwt-wcas-suggestion[data-post-type="product"]');
        const brandElements = $('.dgwt-wcas-suggestion-taxonomy, .dgwt-wcas-suggestion[data-taxonomy]');
        console.log('[PAM FiboSearch] Found', productElements.length, 'product elements');
        console.log('[PAM FiboSearch] Found', brandElements.length, 'brand/taxonomy elements');
        
        // Filter products - try multiple selectors
        $('.dgwt-wcas-suggestion-product, .dgwt-wcas-suggestion[data-post-type="product"]').each(function() {
            const $item = $(this);
            
            // Skip if already hidden
            if ($item.attr('data-pam-hidden')) {
                return;
            }
            
            const productId = parseInt($item.attr('data-product-id') || $item.attr('data-post-id') || $item.data('post-id') || 0);
            
            console.log('[PAM FiboSearch] Checking product:', productId, 'Restricted?', restrictedProducts.includes(productId));
            
            if (productId && restrictedProducts.includes(productId)) {
                console.log('[PAM FiboSearch] HIDING product:', productId);
                $item.hide().attr('data-pam-hidden', 'true');
                productsFiltered++;
            }
        });
        
        // Filter brand/tag taxonomy items - try multiple approaches
        $('.dgwt-wcas-suggestion-taxonomy, .dgwt-wcas-suggestion[data-taxonomy], .dgwt-wcas-suggestion').each(function() {
            const $item = $(this);
            
            // Skip if already hidden
            if ($item.attr('data-pam-hidden')) {
                return;
            }
            
            const text = $item.text().toLowerCase();
            const dataType = $item.attr('data-post-type') || $item.attr('data-taxonomy') || '';
            
            // Skip if it's a product
            if (dataType === 'product') {
                return;
            }
            
            // Check if this is a restricted brand
            for (let i = 0; i < restrictedBrands.length; i++) {
                if (text.includes(restrictedBrands[i].toLowerCase())) {
                    console.log('[PAM FiboSearch] HIDING brand/tag:', text, 'matches', restrictedBrands[i]);
                    $item.hide().attr('data-pam-hidden', 'true');
                    brandsFiltered++;
                    break;
                }
            }
        });
        
        console.log('[PAM FiboSearch] Filtered', productsFiltered, 'products and', brandsFiltered, 'brands/tags');
        
        // Hide empty sections
        $('.dgwt-wcas-suggestions').each(function() {
            const $section = $(this);
            if ($section.find('.dgwt-wcas-suggestion').length === 0) {
                $section.hide();
            }
        });
    }
    
})(jQuery);

