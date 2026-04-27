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

        const contactEntityName = this.afCheckoutBlock.afFieldset.getEntity().data?.contact_id;
        if (!contactEntityName) {
          this.loading = false;
          return;
        }

        const processorConfig = this.afCheckoutBlock.getCheckoutOption();
        const paymentProcessorId = processorConfig.payment_processor_id;

        $scope.$watch(
          () => {
            const contactData = this.afCheckoutBlock.afForm.getData(contactEntityName);
            return contactData && contactData[0] && contactData[0].fields.id;
          },
          (contactId) => {
            if (!contactId) {
              this.accountOptions = [];
              this.loading = false;
              return;
            }
            this.loading = true;
            crmApi4('PaymentToken', 'get', {
              select: ['id', 'masked_account_number'],
              where: [
                ['contact_id', '=', contactId],
                ['payment_processor_id', '=', paymentProcessorId],
              ],
            }).then((tokens) => {
              this.accountOptions = tokens.map(t => ({
                id: t.id,
                label: t.masked_account_number,
              }));
              this.loading = false;
            });
          }
        );
      };
    },
  });
})(angular, CRM.$, CRM._);