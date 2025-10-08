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
    
    // Watch for FiboSearch results
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                filterResults();
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
        
        // Filter products
        $('.dgwt-wcas-suggestion-product').each(function() {
            const $item = $(this);
            const productId = parseInt($item.attr('data-product-id') || $item.attr('data-post-id') || 0);
            
            if (productId && restrictedProducts.includes(productId)) {
                $item.remove();
                productsFiltered++;
            }
        });
        
        // Filter brand/tag taxonomy items
        $('.dgwt-wcas-suggestion-taxonomy').each(function() {
            const $item = $(this);
            const text = $item.text().toLowerCase();
            
            // Check if this is a restricted brand
            for (let i = 0; i < restrictedBrands.length; i++) {
                if (text.includes(restrictedBrands[i].toLowerCase())) {
                    $item.remove();
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

