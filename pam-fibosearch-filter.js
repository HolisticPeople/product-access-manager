/**
 * Product Access Manager - FiboSearch Filter
 * Client-side filtering for FiboSearch results
 * Version: 1.7.3
 * - Dynamic brand detection from access tags
 * - Remove (not hide) restricted items
 * - Smart VIEW MORE count - subtract TOTAL restricted products
 * - Aggressive taxonomy selector targeting
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
        
        var filteredProducts = 0;
        var filteredTaxonomies = 0;
        
        // Extract brand names dynamically from access tags
        var restrictedBrands = [];
        $('.dgwt-wcas-suggestion-taxonomy, .dgwt-wcas-st, .js-dgwt-wcas-suggestion').each(function() {
            var $item = $(this);
            var text = $item.text().toLowerCase().trim();
            
            // If this is an access-* tag, extract the brand name
            if (text.indexOf('access-') === 0) {
                var match = text.match(/^access-([^-]+)/);
                if (match && match[1]) {
                    var brand = match[1];
                    if (restrictedBrands.indexOf(brand) === -1) {
                        restrictedBrands.push(brand);
                        console.log('[PAM] Detected restricted brand from tag:', brand);
                    }
                }
            }
        });
        
        console.log('[PAM] Restricted brands:', restrictedBrands.join(', '));
        
        // 1. Filter product suggestions
        $('.dgwt-wcas-suggestion-product, .dgwt-wcas-suggestion, .dgwt-wcas-sp').each(function() {
            var $item = $(this);
            
            // Skip if this is a taxonomy suggestion
            if ($item.hasClass('dgwt-wcas-suggestion-taxonomy') || $item.closest('.dgwt-wcas-suggestion-taxonomy').length) {
                return;
            }
            
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
                filteredProducts++;
                console.log('[PAM] Hiding restricted product ID:', productId);
            }
        });
        
        // 2. Filter taxonomy suggestions (brands/tags with "access-" prefix)
        // Try EVERY possible selector for taxonomy items
        var taxonomySelectors = [
            '.dgwt-wcas-suggestion-taxonomy',
            '.dgwt-wcas-st',
            '.js-dgwt-wcas-suggestion',
            '.dgwt-wcas-suggestion',
            'li[class*="taxonomy"]',
            'li[class*="brand"]',
            '.dgwt-wcas-suggestions-wrapp li'
        ];
        
        $(taxonomySelectors.join(', ')).each(function() {
            var $item = $(this);
            
            // Skip if already processed
            if ($item.data('pam-processed')) {
                return;
            }
            $item.data('pam-processed', true);
            
            var taxonomySlug = $item.data('taxonomy') || $item.attr('data-taxonomy') || '';
            var termSlug = $item.data('term') || $item.data('slug') || $item.data('value') || $item.attr('data-term') || '';
            var termName = $item.data('name') || $item.attr('data-name') || '';
            
            // Also check text content for "access-" tags and brand names
            var text = $item.text().toLowerCase().trim();
            
            console.log('[PAM] Checking taxonomy item:', {
                text: text,
                termSlug: termSlug,
                termName: termName,
                classes: $item.attr('class')
            });
            
            // Hide if it's an access-* tag
            if (termSlug.indexOf('access-') === 0 || text.indexOf('access-') !== -1) {
                $item.remove(); // REMOVE instead of hide
                filteredTaxonomies++;
                console.log('[PAM] Removing restricted tag:', termSlug || text);
                return;
            }
            
            // Check against dynamically detected restricted brands
            for (var i = 0; i < restrictedBrands.length; i++) {
                var brand = restrictedBrands[i];
                if (text === brand || termSlug === brand || termName.toLowerCase() === brand) {
                    $item.remove(); // REMOVE instead of hide
                    filteredTaxonomies++;
                    console.log('[PAM] Removing restricted brand:', text || termName || brand);
                    return;
                }
            }
        });
        
        // 3. Hide "BRANDS" and "TAGS" headers if all items in those sections are removed
        $('.dgwt-wcas-suggestion-group').each(function() {
            var $group = $(this);
            var $allItems = $group.find('.dgwt-wcas-suggestion, .dgwt-wcas-st');
            
            if ($allItems.length === 0) {
                $group.remove(); // REMOVE instead of hide
                console.log('[PAM] Removing empty group:', $group.find('.dgwt-wcas-suggestion-group-head').text());
            }
        });
        
        // 4. Update or remove "VIEW MORE" button based on TOTAL restricted products count
        // Use pamRestrictedProducts.length (total restricted) not filteredProducts (only visible ones removed)
        if (pamRestrictedProducts && pamRestrictedProducts.length > 0) {
            $('.dgwt-wcas-suggestion-more, .js-dgwt-wcas-suggestion-more').each(function() {
                var $viewMore = $(this);
                var originalText = $viewMore.text();
                
                console.log('[PAM] Found VIEW MORE button:', originalText);
                
                // Extract the number from "» VIEW MORE (60)"
                var match = originalText.match(/\((\d+)\)/);
                
                if (match) {
                    var originalCount = parseInt(match[1]);
                    var totalRestrictedProducts = pamRestrictedProducts.length;
                    var newCount = originalCount - totalRestrictedProducts;
                    
                    console.log('[PAM] VIEW MORE calculation: ' + originalCount + ' (server count) - ' + totalRestrictedProducts + ' (total restricted) = ' + newCount);
                    
                    if (newCount <= 0) {
                        // Remove the button if no more products
                        console.log('[PAM] Removing VIEW MORE button - no products remaining');
                        $viewMore.remove();
                    } else {
                        // Update the count
                        var newText = originalText.replace(/\((\d+)\)/, '(' + newCount + ')');
                        $viewMore.text(newText);
                        console.log('[PAM] Updated VIEW MORE: "' + originalText + '" → "' + newText + '"');
                    }
                } else {
                    console.log('[PAM] Could not find count in VIEW MORE text:', originalText);
                }
            });
        }
        
        console.log('[PAM] Filtered ' + filteredProducts + ' products and ' + filteredTaxonomies + ' taxonomies from results');
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
