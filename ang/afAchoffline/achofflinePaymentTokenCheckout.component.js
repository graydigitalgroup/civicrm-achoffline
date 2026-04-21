(function (angular, $, _) {
  angular.module('afAchoffline').component('afAchofflinePaymentTokenCheckout', {
    require: {
      afCheckoutBlock: '^^afCheckoutBlock',
    },
    templateUrl: '~/afAchoffline/achofflinePaymentTokenCheckout.html',
    controller: function ($scope, $element) {
      const ts = $scope.ts = CRM.ts('achoffline');

      this.accountOptions = [];

      this.$onInit = () => {
        Object.defineProperty(this, 'checkout_params', {
          get: () => this.afCheckoutBlock.checkout_params,
          configurable: true,
        });

        // Options were resolved server-side during settings generation,
        // already filtered to contacts the user is authorized to see.
        const processorConfig = this.afCheckoutBlock.getCheckoutOption();
        this.accountOptions = processorConfig.account_options || [];
      };
    },
  });
})(angular, CRM.$, CRM._);