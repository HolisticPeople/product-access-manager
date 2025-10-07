/**
 * Product Access Manager - FiboSearch Filter
 * Client-side filtering for FiboSearch results
 * Version: 1.9.0
 */
(function($) {
    'use strict';
    
    var pamRestrictedProducts = null;
    var pamIsLoading = false;
    var pamRestrictedInCurrentSearch = 0;
    
    // Fetch restricted products from server
    function pamFetchRestrictedProducts() {
        if (pamIsLoading || pamRestrictedProducts !== null) {
            return;
        }
        
        pamIsLoading = true;
        
        $.ajax({
            url: pamData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'pam_get_restricted_products'
            },
            success: function(response) {
                if (response.success) {
                    pamRestrictedProducts = response.data.restricted_ids || [];
                }
                pamIsLoading = false;
            },
            error: function() {
                pamRestrictedProducts = [];
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
        var filteredTaxonomies = 0;
        
        // Extract brand names dynamically from access tags
        var restrictedBrands = [];
        $('.dgwt-wcas-suggestion-taxonomy, .dgwt-wcas-st, .js-dgwt-wcas-suggestion').each(function() {
            var $item = $(this);
            var text = $item.text().toLowerCase().trim();
            
            if (text.indexOf('access-') === 0) {
                var match = text.match(/^access-([^-]+)/);
                if (match && match[1] && restrictedBrands.indexOf(match[1]) === -1) {
                    restrictedBrands.push(match[1]);
                }
            }
        });
        
        // 1. Filter product suggestions
        $('.dgwt-wcas-suggestion-product, .dgwt-wcas-suggestion, .dgwt-wcas-sp').each(function() {
            var $item = $(this);
            
            if ($item.hasClass('dgwt-wcas-suggestion-taxonomy') || $item.closest('.dgwt-wcas-suggestion-taxonomy').length) {
                return;
            }
            
            var productId = $item.data('post-id') || $item.data('product-id') || $item.attr('data-post-id') || $item.attr('data-product-id');
            
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
        
        $(taxonomySelectors.join(', ')).each(function() {
            var $item = $(this);
            
            if ($item.data('pam-processed')) {
                return;
            }
            $item.data('pam-processed', true);
            
            var termSlug = $item.data('term') || $item.data('slug') || $item.data('value') || $item.attr('data-term') || '';
            var termName = $item.data('name') || $item.attr('data-name') || '';
            var text = $item.text().toLowerCase().trim();
            
            // Remove access-* tags
            if (termSlug.indexOf('access-') === 0 || text.indexOf('access-') !== -1) {
                $item.remove();
                filteredTaxonomies++;
                return;
            }
            
            // Remove restricted brands
            for (var i = 0; i < restrictedBrands.length; i++) {
                var brand = restrictedBrands[i];
                if (text === brand || termSlug === brand || termName.toLowerCase() === brand) {
                    $item.remove();
                    filteredTaxonomies++;
                    return;
                }
            }
        });
        
        // 3. Remove empty groups
        $('.dgwt-wcas-suggestion-group').each(function() {
            var $group = $(this);
            if ($group.find('.dgwt-wcas-suggestion, .dgwt-wcas-st').length === 0) {
                $group.remove();
            }
        });
        
        // 4. Update or remove VIEW MORE button
        if (pamRestrictedInCurrentSearch > 0) {
            $('.dgwt-wcas-suggestion-more, .js-dgwt-wcas-suggestion-more').each(function() {
                var $viewMore = $(this);
                var match = $viewMore.text().match(/\((\d+)\)/);
                
                if (match) {
                    var newCount = parseInt(match[1]) - pamRestrictedInCurrentSearch;
                    
                    if (newCount <= 0) {
                        $viewMore.remove();
                    } else {
                        $viewMore.text($viewMore.text().replace(/\((\d+)\)/, '(' + newCount + ')'));
                    }
                }
            });
        }
    }
    
    // Initialize
    $(document).ready(function() {
        pamFetchRestrictedProducts();
        
        // Intercept AJAX to count all restricted products
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && settings.url.indexOf('dgwt_wcas') !== -1) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.suggestions) {
                        var restrictedCount = 0;
                        for (var i = 0; i < response.suggestions.length; i++) {
                            var suggestion = response.suggestions[i];
                            if (suggestion.type === 'product' && suggestion.post_id) {
                                if (pamRestrictedProducts && pamRestrictedProducts.indexOf(parseInt(suggestion.post_id)) !== -1) {
                                    restrictedCount++;
                                }
                            }
                        }
                        pamRestrictedInCurrentSearch = restrictedCount;
                    }
                } catch (e) {
                    // Silent fail
                }
            }
        });
        
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
        
        // Backup filter trigger
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url && (settings.url.indexOf('dgwt_wcas') !== -1 || settings.url.indexOf('action=dgwt') !== -1)) {
                pamFilterFiboResults();
            }
        });
    });
})(jQuery);