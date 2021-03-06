/**
 * Created by Boxedsolutions on 2017-03-01.
 */
window.method = null;
window.address_selected = null;
window.hash = null;
window.latestScroll = null;

var BillmateIframe = new function(){
    var self = this;
    var childWindow = null;

    this.updateAddress = function (data) {
        // When address in checkout updates;
        data.action = 'billmate_update_address';
        self.showCheckoutLoading();
        jQuery.ajax({
            url : billmate.ajax_url,
            data: data,
            type: 'POST',
            success: function(response){

                if(response.hasOwnProperty("success") && response.success) {
                    window.address_selected = true;
                }
                self.hideCheckoutLoading();
            }
        });

    };
    this.updateBillmate = function(){
        self.showCheckoutLoading();
        jQuery.ajax({
            url : billmate.ajax_url,
            data: {action: 'billmate_update_address',hash: window.hash},
            type: 'POST',
            success: function(response){

                if(response.hasOwnProperty("success") && response.success) {
                    window.address_selected = true;
                }
                self.hideCheckoutLoading();
                self.updateCheckout();
            }
        });
    };
    this.updatePaymentMethod = function(data){
        if(window.method != data.method) {
            data.action = 'billmate_set_method';
            self.showCheckoutLoading();
            jQuery.ajax({
                url: billmate.ajax_url,
                data: data,
                type: 'POST',
                success: function (response) {
                    if (response.hasOwnProperty("success") && response.success) {

                        if(response.hasOwnProperty("data") && response.data.hasOwnProperty("update_checkout") && response.data.update_checkout == true) {
                            jQuery( 'body' ).trigger( 'update_checkout' );
                            self.updateCheckout();
                        } else {
                            self.hideCheckoutLoading();
                        }
                        window.method = data.method;
                    }
                }
            });
        }

    };
    this.updateShippingMethod = function(){

    }
    this.createOrder = function(data){
        // Create Order
        data.action = 'billmate_complete_order';
        self.showCheckoutLoading();
        jQuery.ajax({
            url : billmate.ajax_url,
            data: data,
            type: 'POST',
            success: function(response){
                if(response.hasOwnProperty("success") && response.success)
                    location.href=response.data.url;
            }
        });

    };
    this.updateTotals = function(){
        self.showCheckoutLoading();
        jQuery.ajax({
            url : UPDATE_TOTALS_URL,
            type: 'POST',
            success: function(response){
                jQuery('#billmate-totals').html(response);
            }
        });
    };
    this.initListeners = function () {
        jQuery(document).ready(function () {
            window.addEventListener("message",self.handleEvent);

        });

        /* Listen to WooCommerce checkout elements */
        jQuery(document).on('click', "input[name='update_cart']", function() {
            jQuery('#checkoutdiv').addClass('loading');
            jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
        });
        jQuery( document ).on('click', 'div.woocommerce > form input[type=submit]', function() {
                jQuery('#checkoutdiv').addClass('loading');
                jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
        });
        jQuery( document ).on('keypress', 'div.woocommerce > form input[type=number]', function() {
                jQuery('#checkoutdiv').addClass('loading');
                jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
        });
        jQuery( document ).on('submit', 'div.woocommerce:not(.widget_product_search) > form', function() {
                jQuery('#checkoutdiv').addClass('loading');
                jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
        });
        jQuery( document ).on('click', 'a.woocommerce-remove-coupon', function() {
                jQuery('#checkoutdiv').addClass('loading');
                jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
        });
        jQuery( document ).on('click', 'td.product-remove > a', function() {
                jQuery('#checkoutdiv').addClass('loading');
                jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
        });
        jQuery( document ).on('change', 'select.shipping_method, input[name^=shipping_method]', function() {
                jQuery('#checkoutdiv').addClass('loading');
                jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
        });

        /* Updated cart totals */
        jQuery(document.body).on('updated_cart_totals',function(e){
            self.updateBillmate();
        });

    }
    this.handleEvent = function(event){
        if(event.origin == "https://checkout.billmate.se") {
            try {
                var json = JSON.parse(event.data);
            } catch (e) {
                return;
            }
            self.childWindow = json.source;
            switch (json.event) {
                case 'address_selected':
                    self.updateAddress(json.data);
                    self.updatePaymentMethod(json.data);
                    //self.updateTotals();
                    break;
                case 'payment_method_selected':

                    if (window.address_selected !== null) {
                        self.updatePaymentMethod(json.data);
                        //self.updateTotals();
                    }
                    break;
                case 'checkout_success':
                    self.createOrder(json.data);
                    break;
                case 'content_height':
                    jQuery('#checkout').height(json.data);
                    break;
                case 'content_scroll_position':
                    window.latestScroll = jQuery(document).find( "#checkout" ).offset().top + json.data;
                    jQuery('html, body').animate({scrollTop: jQuery(document).find( "#checkout" ).offset().top + json.data}, 400);
                    break;
                case 'checkout_loaded':
                    self.hideCheckoutLoading();
                    break;
                default:
                    break;

            }
        }

    };

    this.updateCheckout = function(){
        var win = document.getElementById('checkout').contentWindow;
        win.postMessage(JSON.stringify({event: 'update_checkout'}),'*')
    }

    var showCheckoutLoadingCounter = 0;
    this.showCheckoutLoading = function() {
        showCheckoutLoadingCounter++;
        jQuery('#checkoutdiv').addClass('loading');
        jQuery("#checkoutdiv.loading .billmateoverlay").height(jQuery("#checkoutdiv").height());
    }

    this.hideCheckoutLoading = function() {
        showCheckoutLoadingCounter--;
        if(showCheckoutLoadingCounter < 1) {
            showCheckoutLoadingCounter = 0;
        }

        if(showCheckoutLoadingCounter < 1) {
            jQuery('#checkoutdiv').removeClass('loading');
        }
    }

};

var b_iframe = BillmateIframe;
b_iframe.initListeners();
jQuery(document).ready(function(){
    jQuery(document).on('click','.billmate-item-remove',function(e){
        e.preventDefault();

        ancestor = $(this).closest('tr').find('td.product-quantity');
        item_row = $(this).closest('tr');
        cart_item_key = item_row.data('cart_item');
        jQuery.ajax({
            url: '',
            data: {
                action: 'billmate_checkout_remove_item',
                cart_item_key_remove: cart_item_key
            },
            
        })

    })
});
