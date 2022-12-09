define(
    [
        'ko',
        'https://sdk.onvopay.com/sdk.js',
        'Magento_Payment/js/view/payment/cc-form',
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        'mage/storage',
        'uiRegistry',
        'domReady!',
        'Magento_Checkout/js/model/shipping-save-processor',
        'Magento_Checkout/js/action/set-billing-address',
        'Magento_Ui/js/model/messageList'
    ],
    function (ko, onvo, Component, $, quote, customer,
              validator, storage, uiRegistry, domReady,
              shippingSaveProcessor, setBillingAddress,
              globalMessageList
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'ONVO_Pay/payment/form',
                transactionResult: '',
                renderProperties: {
                    shippingMethodCode: '',
                    quoteBaseGrandTotal: '',
                    shippingAddress: '',
                    billingAddress: '',
                    guestEmail: '',
                    isLoggedIn: '',
                }
            },

            myAjax: function(
                method, url, data = {},
                contentType = "application/json",
                xhttp = null, auth = null
            ) {
                return new Promise((resolve) => {
                    if (!xhttp) {
                        xhttp = new XMLHttpRequest();
                    }
                    xhttp.onreadystatechange = function () {
                        if (xhttp.readyState === 4) {
                            resolve((xhttp.responseText));
                        }
                    };
                    xhttp.open(method, url, true);
                    if (auth) {
                        xhttp.setRequestHeader("Authorization", auth);
                    }
                    if (method === "POST" && data) {
                        xhttp.setRequestHeader("Content-type", contentType);
                        xhttp.send(data);
                    } else {
                        xhttp.send();
                    }
                    return xhttp;
                });
            },

            initObservable: function () {
                this._super().observe([
                    'onvoPaymentIntentId',
                    'isIframeLoaded',
                    'isVisiblePaymentButton',
                    'iframeOrderData',
                    'isFormLoading',
                    'iframeLoaded'
                ]);
                this.iframeOrderData('');
                this.onvoPaymentIntentId('');
                this.isFormLoading(false);
                this.iframeLoaded(false);

                let shippingMethodCode = '';
                if (quote.shippingMethod._latestValue) {
                    shippingMethodCode = quote.shippingMethod._latestValue.method_code;
                }
                this.renderProperties.shippingMethod = shippingMethodCode;

                let shippingAddress = '';
                if (quote.shippingAddress()) {
                    shippingAddress = JSON.stringify(quote.shippingAddress());
                }
                this.renderProperties.shippingAddress = shippingAddress;

                let billingAddress = '';
                if (quote.billingAddress()) {
                    billingAddress = JSON.stringify(quote.billingAddress());
                }
                this.renderProperties.billingAddress = billingAddress;

                this.renderProperties.guestEmail = quote.guestEmail;
                this.renderProperties.isLoggedIn = customer.isLoggedIn();
                this.renderProperties.quoteBaseGrandTotal = quote.totals._latestValue.base_grand_total;

                //re-render if change
                quote.totals.subscribe(this.reRender, this);
                quote.billingAddress.subscribe(this.reRender, this);
                customer.isLoggedIn.subscribe(this.reRender, this);
                uiRegistry.get('checkout.steps.billing-step.payment.customer-email').email.subscribe(this.reRender, this);

                return this;
            },

            reRender: function () {
                let hasToReRender = false;

                let baseGrandTotal = quote.totals._latestValue.base_grand_total;
                if (baseGrandTotal !== this.renderProperties.quoteBaseGrandTotal) {
                    this.renderProperties.quoteBaseGrandTotal = baseGrandTotal;
                    hasToReRender = true;
                }

                let shippingMethodCode = '';
                if (quote.shippingMethod._latestValue) {
                    shippingMethodCode = quote.shippingMethod._latestValue.method_code;
                }
                if (shippingMethodCode !== this.renderProperties.shippingMethod) {
                    this.renderProperties.shippingMethod = shippingMethodCode;
                    hasToReRender = true;
                }

                let shippingAddress = '';
                if (quote.shippingAddress()) shippingAddress = JSON.stringify(quote.shippingAddress());
                if (shippingAddress !== this.renderProperties.shippingAddress) {
                    this.renderProperties.shippingAddress = shippingAddress;
                    hasToReRender = true;
                }

                let quoteBilling = quote.billingAddress();
                let billingAddress = quoteBilling ? JSON.stringify(quote.billingAddress()) : '';
                if (billingAddress !== this.renderProperties.billingAddress) {
                    this.renderProperties.billingAddress = billingAddress;
                    hasToReRender = true;
                }

                let actualGuestEmail = quote.guestEmail;
                if (!customer.isLoggedIn() && quote.isVirtual()) {
                    actualGuestEmail = uiRegistry.get('checkout.steps.billing-step.payment.customer-email').email();
                    if (actualGuestEmail !== this.renderProperties.guestEmail) {
                        this.renderProperties.guestEmail = actualGuestEmail;
                        hasToReRender = true;
                    }
                }

                if (customer.isLoggedIn() !== this.renderProperties.isLoggedIn) {
                    this.renderProperties.isLoggedIn = customer.isLoggedIn();
                    hasToReRender = true;
                }

                if (hasToReRender) {
                    this.loadPaymentIntent();
                } else {
                    this.isFormLoading(false);
                }

                return hasToReRender;
            },

            initialize: function () {
                const self = this;
                this._super();
                if (customer.isLoggedIn() && quote.isVirtual() && quote.billingAddress()) {
                    $.when(setBillingAddress()).then(() => {
                        self.initializeForm()
                    });
                } else {
                    this.initializeForm();
                }
            },

            initializeForm: function () {
                console.log('onvo: initializeForm');
                if (!this.reRender()) {
                    this.isFormLoading(true);
                    this.loadPaymentIntent();
                } else {
                    console.log('onvo: no reRender!');
                }
            },

            billingAddressChanges: function () {
                const self = this;
                //if no billing info, then form is editing mode
                if (!quote.billingAddress()) {
                    self.reRender();
                } else if (!quote.isVirtual()) {
                    self.isFormLoading(false);
                    console.log('saveShippingInformation');
                    shippingSaveProcessor.saveShippingInformation().done(function () {
                        self.reRender();
                    });
                }
            },

            validateRenderEmbedForm: function () {
                if (!this.renderProperties.billingAddress) {
                    this._showErrors('Información de pago: complete todos los campos requeridos de para continuar.');
                    return false;
                }

                if (!customer.isLoggedIn() &&
                    quote.isVirtual() &&
                    (!quote.guestEmail || (
                            this.renderProperties.guestEmail &&
                            this.renderProperties.guestEmail !== quote.guestEmail
                        )
                    )
                ) {
                    this._showErrors('Ingrese un email válido para continuar');
                    return false;
                }

                if (!customer.isLoggedIn() &&
                    !quote.isVirtual() &&
                    !quote.guestEmail
                ) {
                    this._showErrors('Ingrese un email válido para continuar');
                    return false;
                }

                return true;
            },

            loadPaymentIntent: function () {
                let self = this;
                if (this.validateRenderEmbedForm()) {
                    let url = self.getPaymentIntentUrl();
                    if(this.onvoPaymentIntentId()) {
                        url = url + `/${this.onvoPaymentIntentId()}`;
                    }
                    let email = quote.guestEmail;
                    if(customer.isLoggedIn()) {
                        email = customer.customerData.email
                    }
                    self.myAjax('GET', `${url}?email=${email}`).then(response => {
                        self.onvoPaymentIntentId(JSON.parse(response).payment_intent_id);
                        if (self.onvoPaymentIntentId()) {
                            self.renderForm();
                        } else {
                            self._showErrors(JSON.parse(response).error);
                        }
                    });
                } else {
                    this.isFormLoading(false);
                }
            },

            renderForm: function () {
                const self = this;
                const initIframe = () => {
                    if (onvo) {
                        document.getElementById("onvoIframeContainer").innerHTML = '';
                        self.iframeLoaded(true)
                        onvo.pay({
                            onError: (data) => {
                                console.log(data);
                                let url = self.getErrorReportUrl();
                                self.myAjax('POST', `${url}`, JSON.stringify(data));
                            },
                            onSuccess: (data) => {
                                self.iframeOrderData(data);
                                self.placeOrder();
                            },
                            publicKey: self.getPublicKey(),
                            paymentIntentId: self.onvoPaymentIntentId(),
                            paymentType: "one_time"
                        }).render('#onvoIframeContainer');
                    }
                }
                if (document.getElementById("onvoIframeContainer")) {
                    initIframe();
                } else {
                    let iframeInterval = setInterval(function () {
                        if (document.getElementById("onvoIframeContainer")) {
                            clearInterval(iframeInterval);
                            initIframe();
                        }
                    }, 700)
                }
            },

            getData: function () {
                if (this.iframeOrderData() !== '') {
                    return {
                        'method': this.getCode(),
                        'additional_data': {
                            'transaction_result' : JSON.stringify(this.iframeOrderData())
                        }
                    };
                } else {
                    return {
                        'method': this.getCode()
                    };
                }
            },

            beforePlaceOrder: function () {
                let self = this;
                if (this.iframeOrderData() !== '') {
                    self.placeOrder();
                }
            },

            validate: function () {
                if (this.iframeOrderData() !== '') {
                    return true;
                }
            },

            getCode: function () {
                return 'onvo';
            },

            /**
             * Check if payment is active
             *
             * @returns {Boolean}
             */
            isActive: function () {
                return this.getCode() == this.isChecked()
            },

            getConfig: function () {
                return window.checkoutConfig.payment.onvo
            },

            getPublicKey: function () {
                return this.getConfig().publicKey;
            },

            getPaymentIntentUrl: function () {
                return this.getConfig().paymentIntentUrl;
            },

            isLoggedIn: function () {
                return customer.isLoggedIn();
            },

            /**
             * Show error messages
             * @param msg
             * @private
             */
            _showErrors: function (msg) {
                jQuery(window).scrollTop(0);
                globalMessageList.addErrorMessage({
                    message: msg
                });
            },
        });
    }
);
