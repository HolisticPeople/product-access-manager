/**
 * Product Access Manager - FiboSearch Client-Side Filtering
 * Version: 2.2.0
 * 
 * Filters FiboSearch results on the client-side because FiboSearch uses SHORTINIT mode
 * which bypasses our server-side PHP filters.
 * 
 * Production ready - all debug logging removed.
 */
(function($) {
    'use strict';
    
    var pamRestrictedProducts = null;
    var pamRestrictedProductUrls = [];
    var pamIsLoading = false;
    var pamRestrictedBrands = [];
    
    // Fetch restricted products from server
    function pamFetchRestrictedProducts() {
        if (pamIsLoading || pamRestrictedProducts !== null) {
            return;
        }
        
        pamIsLoading = true;
        
        $.ajax({
            url: pamFiboFilter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pam_get_restricted_data'
            },
        success: function(response) {
            if (response.success) {
                pamRestrictedProducts = response.data.products || [];
                pamRestrictedProductUrls = response.data.product_urls || [];
                pamRestrictedBrands = response.data.brands || [];
                
                // Trigger filter after data loads
                pamFilterFiboResults();
            }
            pamIsLoading = false;
        },
            error: function(xhr, status, error) {
                pamRestrictedProducts = [];
                pamRestrictedProductUrls = [];
                pamRestrictedBrands = [];
                pamIsLoading = false;
            }
        });
    }
    
    // Filter FiboSearch results
    function pamFilterFiboResults() {
        if (pamRestrictedProducts === null || pamRestrictedProducts.length === 0) {
            return;
        }
        
        var filteredProducts = 0;
        var filteredBrands = 0;
        
        var $suggestions = $('.dgwt-wcas-suggestion-product, .dgwt-wcas-suggestion, .dgwt-wcas-sp');
        
        // 1. Filter product suggestions
        $suggestions.each(function() {
            var $item = $(this);
            
            // Skip if already removed
            if ($item.data('pam-removed')) {
                return;
            }
            
            // Skip taxonomy items
            if ($item.hasClass('dgwt-wcas-suggestion-taxonomy') || $item.closest('.dgwt-wcas-suggestion-taxonomy').length) {
                return;
            }
            
            // Get product ID
            var productId = $item.data('post-id') || $item.data('product-id') || $item.attr('data-post-id') || $item.attr('data-product-id');
            
            if (!productId) {
                var url = $item.find('a').first().attr('href') || '';
                var match = url.match(/[?&]p=(\d+)|\/product\/[^\/]+\/(\d+)|post_type=product.*?(\d+)/);
                if (match) {
                    productId = parseInt(match[1] || match[2] || match[3]);
                }
            }
            
            if (productId && pamRestrictedProducts.indexOf(parseInt(productId)) !== -1) {
                $item.data('pam-removed', true);
                $item.remove();
                filteredProducts++;
            }
        });
        
        // 2. Filter taxonomy suggestions (brands/tags)
        var taxonomySelectors = [
            '.dgwt-wcas-suggestion-taxonomy',
            '.dgwt-wcas-st',
            '.js-dgwt-wcas-suggestion',
            '.dgwt-wcas-suggestion',
            'li[class*="taxonomy"]',
            'li[class*="brand"]',
            '.dgwt-wcas-suggestions-wrapp li'
        ];
        
        var $taxonomies = $(taxonomySelectors.join(', '));
        
        $taxonomies.each(function() {
            var $item = $(this);
            
            // Skip if already processed
            if ($item.data('pam-processed')) {
                return;
            }
            $item.data('pam-processed', true);
            
            var text = $item.text().toLowerCase().trim();
            
            // Check if this is a restricted brand
            for (var i = 0; i < pamRestrictedBrands.length; i++) {
                var brand = pamRestrictedBrands[i].toLowerCase();
                if (text.indexOf(brand) !== -1) {
                    $item.data('pam-removed', true);
                    $item.remove();
                    filteredBrands++;
                    break;
                }
            }
        });
        
        // 3. Filter RIGHT PANEL details - match URLs directly with restricted product URLs
        $('.dgwt-wcas-details-wrapp .dgwt-wcas-details-inner').each(function() {
            var $detailsInner = $(this);
            
            // Skip if already removed
            if ($detailsInner.data('pam-removed')) {
                return;
            }
            
            // Find product links in the details panel
            var $productLinks = $detailsInner.find('a[href*="/product/"]');
            var shouldRemove = false;
            
            // Check if any link in the details panel matches a restricted product URL
            $productLinks.each(function() {
                var url = $(this).attr('href') || '';
                
                // Normalize URLs for comparison (remove trailing slash, query params, etc.)
                var normalizedUrl = url.split('?')[0].replace(/\/$/, '');
                
                // Check if this URL matches any restricted product URL
                for (var i = 0; i < pamRestrictedProductUrls.length; i++) {
                    var restrictedUrl = pamRestrictedProductUrls[i].split('?')[0].replace(/\/$/, '');
                    
                    if (normalizedUrl === restrictedUrl) {
                        shouldRemove = true;
                        return false; // break
                    }
                }
                
                if (shouldRemove) {
                    return false; // break outer loop
                }
            });
            
            if (shouldRemove) {
                $detailsInner.data('pam-removed', true);
                $detailsInner.remove();
                
                // Also hide the entire details wrapper if it's empty
                var $detailsWrapp = $('.dgwt-wcas-details-wrapp');
                if ($detailsWrapp.find('.dgwt-wcas-details-inner:visible').length === 0) {
                    $detailsWrapp.hide();
                }
            }
        });
        
        // 4. Remove empty groups
        $('.dgwt-wcas-suggestion-group').each(function() {
            var $group = $(this);
            if ($group.find('.dgwt-wcas-suggestion:visible, .dgwt-wcas-st:visible').length === 0) {
                $group.hide();
            }
        });
    }
    
    // Initialize
    $(document).ready(function() {
        pamFetchRestrictedProducts();
        
        // Watch for results using MutationObserver
        var observer = new MutationObserver(function() {
            pamFilterFiboResults();
        });
        
        var containers = document.querySelectorAll('.dgwt-wcas-suggestions-wrapp, .dgwt-wcas-search-wrapp, .dgwt-wcas-preloader');
        containers.forEach(function(container) {
            observer.observe(container, {
                childList: true,
                subtree: true
            });
        });
        
        // Backup filter trigger - run after AJAX completes
        // Run multiple times with delays to catch FiboSearch re-renders
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && (settings.url.indexOf('dgwt_wcas') !== -1 || settings.url.indexOf('action=dgwt') !== -1)) {
                pamFilterFiboResults(); // Immediate
                setTimeout(pamFilterFiboResults, 50);  // After 50ms
                setTimeout(pamFilterFiboResults, 150); // After 150ms
                setTimeout(pamFilterFiboResults, 300); // After 300ms
            }
        });
    });
})(jQuery);

