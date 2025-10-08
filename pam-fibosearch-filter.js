/**
 * Product Access Manager - FiboSearch Client-Side Filtering
 * Version: 2.1.6
 * 
 * Filters FiboSearch results on the client-side because FiboSearch uses SHORTINIT mode
 * which bypasses our server-side PHP filters.
 */
(function($) {
    'use strict';
    
    var pamRestrictedProducts = null;
    var pamIsLoading = false;
    var pamRestrictedBrands = [];
    
    console.log('[PAM FiboSearch] Script loaded, AJAX URL:', pamFiboFilter.ajaxUrl);
    
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
            console.log('[PAM FiboSearch] AJAX response:', response);
            if (response.success) {
                pamRestrictedProducts = response.data.products || [];
                pamRestrictedBrands = response.data.brands || [];
                console.log('[PAM FiboSearch] Restricted products:', pamRestrictedProducts.length);
                console.log('[PAM FiboSearch] Restricted brands:', pamRestrictedBrands);
                
                // CRITICAL: Trigger filter after data loads!
                console.log('[PAM FiboSearch] Data loaded - triggering immediate filter');
                pamFilterFiboResults();
            }
            pamIsLoading = false;
        },
            error: function(xhr, status, error) {
                console.error('[PAM FiboSearch] AJAX error:', error, xhr.responseText);
                pamRestrictedProducts = [];
                pamRestrictedBrands = [];
                pamIsLoading = false;
            }
        });
    }
    
    // Filter FiboSearch results
    function pamFilterFiboResults() {
        if (pamRestrictedProducts === null || pamRestrictedProducts.length === 0) {
            console.log('[PAM FiboSearch] No restricted products to filter');
            return;
        }
        
        var filteredProducts = 0;
        var filteredBrands = 0;
        
        console.log('[PAM FiboSearch] Starting filter - restricted products:', pamRestrictedProducts.length, 'restricted brands:', pamRestrictedBrands);
        
        // DEBUG: Check if selectors find any elements
        var $suggestions = $('.dgwt-wcas-suggestion-product, .dgwt-wcas-suggestion, .dgwt-wcas-sp');
        console.log('[PAM FiboSearch] Found', $suggestions.length, 'potential product elements');
        
        // 1. Filter product suggestions - exact selectors from v1.9.0
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
            
            // DEBUG: Log all attributes to see what's available
            var attrs = {};
            $.each($item[0].attributes, function(idx, attr) {
                attrs[attr.nodeName] = attr.nodeValue;
            });
            console.log('[PAM FiboSearch] Product element attributes:', attrs);
            
            // Get product ID - exact logic from v1.9.0
            var productId = $item.data('post-id') || $item.data('product-id') || $item.attr('data-post-id') || $item.attr('data-product-id');
            
            if (!productId) {
                var url = $item.find('a').first().attr('href') || '';
                console.log('[PAM FiboSearch] Product URL:', url);
                var match = url.match(/[?&]p=(\d+)|\/product\/[^\/]+\/(\d+)|post_type=product.*?(\d+)/);
                if (match) {
                    productId = parseInt(match[1] || match[2] || match[3]);
                }
            }
            
            console.log('[PAM FiboSearch] Checking product ID:', productId, 'Restricted?', pamRestrictedProducts.indexOf(parseInt(productId)) !== -1);
            
            if (productId && pamRestrictedProducts.indexOf(parseInt(productId)) !== -1) {
                console.log('[PAM FiboSearch] REMOVING product:', productId);
                $item.data('pam-removed', true); // Mark as removed
                $item.remove();
                filteredProducts++;
            }
        });
        
        // 2. Filter taxonomy suggestions (brands/tags) - exact selectors from v1.9.0
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
        console.log('[PAM FiboSearch] Found', $taxonomies.length, 'potential taxonomy elements');
        
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
                    console.log('[PAM FiboSearch] REMOVING brand:', text, 'matches', pamRestrictedBrands[i]);
                    $item.data('pam-removed', true); // Mark as removed
                    $item.remove();
                    filteredBrands++;
                    break;
                }
            }
        });
        
        // 3. Filter RIGHT PANEL details (simpler approach - check all detail items)
        $('.dgwt-wcas-details-wrapp .dgwt-wcas-details-inner').each(function() {
            var $detailsInner = $(this);
            
            // Skip if already removed
            if ($detailsInner.data('pam-removed')) {
                return;
            }
            
            // Find all links in this details panel
            var $productLinks = $detailsInner.find('a[href*="/product/"]');
            var shouldRemove = false;
            
            $productLinks.each(function() {
                var url = $(this).attr('href') || '';
                
                // Try to match product slug with left panel items
                $('.dgwt-wcas-suggestion').each(function() {
                    var $suggestion = $(this);
                    var $suggestionLink = $suggestion.find('a').first();
                    var suggestionUrl = $suggestionLink.attr('href') || '';
                    
                    // If URLs match, check if this suggestion is restricted
                    if (suggestionUrl && url.indexOf(suggestionUrl) !== -1) {
                        var productId = $suggestion.data('post-id') || $suggestion.data('product-id') || $suggestion.attr('data-post-id');
                        if (productId && pamRestrictedProducts.indexOf(parseInt(productId)) !== -1) {
                            shouldRemove = true;
                            console.log('[PAM FiboSearch] RIGHT PANEL: Found restricted product', productId, 'via URL match');
                            return false; // break
                        }
                    }
                });
                
                if (shouldRemove) {
                    return false; // break outer loop
                }
            });
            
            if (shouldRemove) {
                console.log('[PAM FiboSearch] REMOVING right panel details');
                $detailsInner.data('pam-removed', true);
                $detailsInner.remove();
                
                // Also try to hide the entire details wrapper if it's empty
                var $detailsWrapp = $('.dgwt-wcas-details-wrapp');
                if ($detailsWrapp.find('.dgwt-wcas-details-inner:visible').length === 0) {
                    $detailsWrapp.hide();
                }
            }
        });
        
        console.log('[PAM FiboSearch] Filtered', filteredProducts, 'products and', filteredBrands, 'brands');
        
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

