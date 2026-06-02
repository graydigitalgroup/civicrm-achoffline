(function (angular, $, _) {
  angular.module('afACHOffline').component('afAchofflinePaymentTokenCheckout', {
    require: {
      afCheckoutBlock: '^^afCheckoutBlock',
    },
    templateUrl: '~/afACHOffline/achofflinePaymentTokenCheckout.html',
    controller: function ($scope, $element, crmApi4) {
      const ts = $scope.ts = CRM.ts('achoffline');

      this.accountOptions = [];
      this.loading = true;

      this.$onInit = () => {
        Object.defineProperty(this, 'checkout_params', {
          get: () => this.afCheckoutBlock.checkout_params,
          configurable: true,
        });

        this.contactEntityName = this.afCheckoutBlock.afFieldset.getEntity().data?.contact_id;
        const rawProcessorId = this.afCheckoutBlock.getCheckoutOption().payment_processor_id;
        this.paymentProcessorId = rawProcessorId != null ? Number(rawProcessorId) : null;
        this.loading = false;
      };

      this.getValidTokens = (() => {
        let cached = [];
        let cachedKey = null;

        return () => {
          if (!this.contactEntityName) return cached;

          const contactData = this.afCheckoutBlock.afForm.getData(this.contactEntityName);
          const tokens = contactData?.[0]?.joins?.PaymentToken || [];
          // Compose a cheap cache key from the inputs the filter depends on.
          // Token id list + processor id + minute-resolution time — enough to
          // catch real changes, not so granular it busts the cache every digest.
          const key = tokens.map(t => t.id).join(',') + ':' + this.paymentProcessorId + ':' + Math.floor(Date.now() / 60000);

          if (key === cachedKey) {
            return cached;
          }

          const now = new Date();
          cached = tokens
            .filter(t => t.payment_processor_id === this.paymentProcessorId)
            .filter(t => !t.expiry_date || new Date(t.expiry_date) > now)
            .map(t => ({ id: t.id, label: t.masked_account_number }));
          cachedKey = key;
          return cached;
        };
      })();
    },
  });
})(angular, CRM.$, CRM._);