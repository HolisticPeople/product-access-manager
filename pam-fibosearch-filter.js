/**
 * Product Access Manager - FiboSearch Filter
 * Client-side filtering for FiboSearch results
 */
(function($) {
    'use strict';
    
    var pamRestrictedProducts = null;
    var pamIsLoading = false;
    
    console.log('[PAM] FiboSearch filter script loaded');
    
    // Fetch restricted products from server
    function pamFetchRestrictedProducts() {
        if (pamIsLoading || pamRestrictedProducts !== null) {
            console.log('[PAM] Skip fetch - already loading or loaded');
            return;
        }
        
        pamIsLoading = true;
        console.log('[PAM] Fetching restricted products...');
        
        $.ajax({
            url: pamData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pam_get_restricted_products'
            },
            success: function(response) {
                console.log('[PAM] AJAX response:', response);
                if (response.success) {
                    pamRestrictedProducts = response.data.restricted_ids || [];
                    console.log('[PAM] Loaded ' + pamRestrictedProducts.length + ' restricted product IDs:', pamRestrictedProducts);
                } else {
                    console.error('[PAM] AJAX failed:', response);
                }
                pamIsLoading = false;
            },
            error: function(xhr, status, error) {
                console.error('[PAM] AJAX error:', status, error);
                pamRestrictedProducts = [];
                pamIsLoading = false;
            }
        });
    }
    
    // Filter FiboSearch results
    function pamFilterFiboResults() {
        if (pamRestrictedProducts === null) {
            console.log('[PAM] Skip filter - restricted products not loaded yet');
            return;
        }
        
        if (pamRestrictedProducts.length === 0) {
            console.log('[PAM] No restricted products to filter');
            return;
        }
        
        console.log('[PAM] Filtering FiboSearch results...');
        
        var filtered = 0;
        
        // Find all product suggestions in FiboSearch results
        $('.dgwt-wcas-suggestion-product, .dgwt-wcas-suggestion, .dgwt-wcas-sp').each(function() {
            var $item = $(this);
            var productId = null;
            
            // Try to get product ID from data attribute
            productId = $item.data('post-id') || $item.data('product-id') || $item.attr('data-post-id') || $item.attr('data-product-id');
            
            // If no data attribute, try to extract from URL
            if (!productId) {
                var url = $item.find('a').first().attr('href') || '';
                var match = url.match(/[?&]p=(\d+)|\/product\/[^\/]+\/(\d+)|post_type=product.*?(\d+)/);
                if (match) {
                    productId = parseInt(match[1] || match[2] || match[3]);
                }
            }
            
            if (productId && pamRestrictedProducts.indexOf(parseInt(productId)) !== -1) {
                $item.hide();
                filtered++;
                console.log('[PAM] Hiding restricted product ID:', productId);
            }
        });
        
        console.log('[PAM] Filtered ' + filtered + ' products from results');
    }
    
    // Initialize
    $(document).ready(function() {
        console.log('[PAM] Document ready - initializing');
        
        // Fetch restricted products immediately
        pamFetchRestrictedProducts();
        
        // Watch for FiboSearch results appearing using MutationObserver
        var observer = new MutationObserver(function(mutations) {
            console.log('[PAM] DOM mutation detected');
            pamFilterFiboResults();
        });
        
        // Observe multiple possible search containers
        var containers = document.querySelectorAll('.dgwt-wcas-suggestions-wrapp, .dgwt-wcas-search-wrapp, .dgwt-wcas-preloader');
        console.log('[PAM] Found ' + containers.length + ' search containers to observe');
        
        containers.forEach(function(container) {
            observer.observe(container, {
                childList: true,
                subtree: true
            });
        });
        
        // Also filter after AJAX complete (backup method)
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && (settings.url.indexOf('dgwt_wcas') !== -1 || settings.url.indexOf('action=dgwt') !== -1)) {
                console.log('[PAM] FiboSearch AJAX detected, filtering results');
                setTimeout(pamFilterFiboResults, 100);
            }
        });
        
        console.log('[PAM] Initialization complete');
    });
})(jQuery);
