/**
 * FiboSearch Client-Side Filter
 * Version: 3.0.0 (Lightweight with cached endpoint)
 * 
 * Filters FiboSearch results to hide restricted products for unauthorized users.
 * Uses server-side cached blocked products for fast, reliable filtering.
 */

(function($) {
    'use strict';
    
    console.log('[PAM FiboFilter] Script loaded v2.5.5');
    
    var pamBlockedProducts = [];
    var pamDataLoaded = false;
    
    /**
     * Fetch blocked products from server (uses 30-min cache!)
     */
    function pamLoadBlockedProducts() {
        console.log('[PAM FiboFilter] Loading blocked products from server...');
        $.ajax({
            url: pamFiboFilter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pam_get_blocked_products'
            },
            success: function(response) {
                console.log('[PAM FiboFilter] AJAX response:', response);
                if (response.success && response.data) {
                    pamBlockedProducts = response.data;
                    pamDataLoaded = true;
                    console.log('[PAM FiboFilter] Loaded ' + pamBlockedProducts.length + ' blocked products');
                    
                    // Filter any existing results
                    pamFilterResults();
                } else {
                    console.error('[PAM FiboFilter] Failed to load blocked products');
                }
            },
            error: function(xhr, status, error) {
                console.error('[PAM FiboFilter] AJAX error:', status, error);
            }
        });
    }
    
    /**
     * Filter FiboSearch results (comprehensive)
     */
    function pamFilterResults() {
        console.log('[PAM FiboFilter] Filtering results...');
        if (!pamDataLoaded || pamBlockedProducts.length === 0) {
            console.log('[PAM FiboFilter] Skipping filter - data not loaded or no blocked products');
            return;
        }
        console.log('[PAM FiboFilter] Filtering with ' + pamBlockedProducts.length + ' blocked products');
        
        var removedCount = 0;
        
        // 1. Filter PRODUCT suggestions (left panel)
        var productSelectors = [
            '.dgwt-wcas-suggestion',
            '.dgwt-wcas-suggestion-product',
            '.dgwt-wcas-sp',
            '.autocomplete-suggestion[data-index]'
        ];
        
        $(productSelectors.join(', ')).each(function() {
            var $item = $(this);
            if ($item.data('pam-removed')) {
                return;
            }
            
            // Extract product ID - try multiple methods
            var productId = $item.attr('data-post-id') || 
                           $item.attr('data-product-id') ||
                           $item.find('a').attr('data-post-id') ||
                           $item.find('a').attr('data-product-id');
            
            // Try URL extraction if no ID found
            if (!productId) {
                var $link = $item.find('a').first();
                if ($link.length) {
                    var href = $link.attr('href') || '';
                    // Try multiple URL patterns
                    var patterns = [
                        /[\?&]product_id=(\d+)/,
                        /\/product\/.*?[\?&]p=(\d+)/,
                        /\/(\d+)\/?$/
                    ];
                    for (var i = 0; i < patterns.length; i++) {
                        var match = href.match(patterns[i]);
                        if (match) {
                            productId = match[1];
                            break;
                        }
                    }
                }
            }
            
            if (productId && pamBlockedProducts.indexOf(parseInt(productId)) !== -1) {
                console.log('[PAM FiboFilter] Removing product ' + productId);
                $item.data('pam-removed', true);
                $item.remove();
                removedCount++;
            }
        });
        
        // 2. Filter TAXONOMY suggestions (brands/tags with Vimergy)
        var taxonomySelectors = [
            '.dgwt-wcas-suggestion-taxonomy',
            '.dgwt-wcas-st'
        ];
        
        $(taxonomySelectors.join(', ')).each(function() {
            var $item = $(this);
            if ($item.data('pam-removed')) {
                return;
            }
            
            var text = $item.text().toLowerCase();
            // Remove if contains "vimergy" or other restricted brands
            if (text.indexOf('vimergy') !== -1) {
                console.log('[PAM FiboFilter] Removing taxonomy item: ' + text);
                $item.data('pam-removed', true);
                $item.remove();
                removedCount++;
            }
        });
        
        // 3. Filter RIGHT PANEL details (hover/selection view)
        $('.dgwt-wcas-details-wrapp .dgwt-wcas-details-inner').each(function() {
            var $details = $(this);
            if ($details.data('pam-removed')) {
                return;
            }
            
            var shouldRemove = false;
            
            // Check all product links in details
            $details.find('a').each(function() {
                var href = $(this).attr('href') || '';
                var productId = $(this).attr('data-post-id') || $(this).attr('data-product-id');
                
                // Try to extract ID from URL if not in attribute
                if (!productId && href.indexOf('/product/') !== -1) {
                    var patterns = [
                        /[\?&]product_id=(\d+)/,
                        /\/product\/.*?[\?&]p=(\d+)/,
                        /\/(\d+)\/?$/
                    ];
                    for (var i = 0; i < patterns.length; i++) {
                        var match = href.match(patterns[i]);
                        if (match) {
                            productId = match[1];
                            break;
                        }
                    }
                }
                
                if (productId && pamBlockedProducts.indexOf(parseInt(productId)) !== -1) {
                    shouldRemove = true;
                    return false; // break
                }
                
                // Also check text for brand names
                var text = $(this).text().toLowerCase();
                if (text.indexOf('vimergy') !== -1) {
                    shouldRemove = true;
                    return false;
                }
            });
            
            if (shouldRemove) {
                console.log('[PAM FiboFilter] Removing details panel');
                $details.data('pam-removed', true);
                $details.remove();
                removedCount++;
                
                // Hide wrapper if no details left
                var $wrapper = $('.dgwt-wcas-details-wrapp');
                if ($wrapper.find('.dgwt-wcas-details-inner:visible').length === 0) {
                    $wrapper.hide();
                }
            }
        });
        
        console.log('[PAM FiboFilter] Removed ' + removedCount + ' items total');
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

