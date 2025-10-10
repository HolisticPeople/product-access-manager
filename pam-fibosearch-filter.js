/**
 * FiboSearch Client-Side Filter
 * Version: 3.0.0 (Lightweight with cached endpoint)
 * 
 * Filters FiboSearch results to hide restricted products for unauthorized users.
 * Uses server-side cached blocked products for fast, reliable filtering.
 */

(function($) {
    'use strict';
    
    var pamBlockedProducts = [];
    var pamDataLoaded = false;
    
    /**
     * Fetch blocked products from server (uses 30-min cache!)
     */
    function pamLoadBlockedProducts() {
        $.ajax({
            url: pamFiboFilter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pam_get_blocked_products'
            },
            success: function(response) {
                if (response.success && response.data) {
                    pamBlockedProducts = response.data;
                    pamDataLoaded = true;
                    
                    // Filter any existing results
                    pamFilterResults();
                }
            }
        });
    }
    
    /**
     * Filter FiboSearch results
     */
    function pamFilterResults() {
        if (!pamDataLoaded || pamBlockedProducts.length === 0) {
            return;
        }
        
        // Filter product suggestions (left panel)
        $('.dgwt-wcas-suggestion').each(function() {
            var $suggestion = $(this);
            if ($suggestion.data('pam-filtered')) {
                return; // Already processed
            }
            
            // Extract product ID from data attribute or URL
            var productId = $suggestion.attr('data-post-id') || 
                           $suggestion.attr('data-product-id') ||
                           $suggestion.find('a').attr('data-post-id');
            
            // If no ID found, try extracting from URL
            if (!productId) {
                var url = $suggestion.find('a').attr('href') || '';
                var match = url.match(/[\?&]product_id=(\d+)/);
                if (match) {
                    productId = match[1];
                }
            }
            
            if (productId && pamBlockedProducts.indexOf(parseInt(productId)) !== -1) {
                $suggestion.data('pam-filtered', true);
                $suggestion.remove();
            }
        });
        
        // Filter details panel (right side on hover)
        $('.dgwt-wcas-details-wrapp .dgwt-wcas-details-inner').each(function() {
            var $details = $(this);
            if ($details.data('pam-filtered')) {
                return;
            }
            
            // Check product links in details
            var shouldRemove = false;
            $details.find('a[href*="/product/"]').each(function() {
                var url = $(this).attr('href') || '';
                
                // Try to match product ID from URL
                var match = url.match(/product\/([^\/\?]+)/);
                if (match) {
                    var slug = match[1];
                    
                    // Check if any blocked product URL contains this slug
                    // (Since we don't have slugs, we'll check the link structure)
                    // For now, we'll use a different approach - check data attributes
                    var productId = $(this).attr('data-post-id') || 
                                   $(this).attr('data-product-id');
                    
                    if (productId && pamBlockedProducts.indexOf(parseInt(productId)) !== -1) {
                        shouldRemove = true;
                        return false; // break
                    }
                }
            });
            
            if (shouldRemove) {
                $details.data('pam-filtered', true);
                $details.remove();
                
                // Hide wrapper if no details left
                var $wrapper = $('.dgwt-wcas-details-wrapp');
                if ($wrapper.find('.dgwt-wcas-details-inner:visible').length === 0) {
                    $wrapper.hide();
                }
            }
        });
    }
    
    /**
     * Initialize filtering
     */
    function pamInit() {
        // Load blocked products immediately
        pamLoadBlockedProducts();
        
        // Filter after AJAX completes
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && settings.url.indexOf('dgwt-wcas') !== -1) {
                // Small delay to let FiboSearch render
                setTimeout(pamFilterResults, 50);
                setTimeout(pamFilterResults, 200);
                setTimeout(pamFilterResults, 500);
            }
        });
        
        // Also use MutationObserver for DOM changes
        if (window.MutationObserver) {
            var observer = new MutationObserver(function() {
                pamFilterResults();
            });
            
            var searchContainer = document.querySelector('.dgwt-wcas-search-wrapp');
            if (searchContainer) {
                observer.observe(searchContainer, {
                    childList: true,
                    subtree: true
                });
            }
        }
    }
    
    // Initialize when ready
    $(document).ready(pamInit);
    
})(jQuery);

