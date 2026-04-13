// js/ach-autocomplete.js
CRM.$(function($) {

  function initBankAccountSelect($context) {
    const $fields = $context.find('[data-api-entity="PaymentToken"]');

    if (!$fields.length) {
      return;
    }

    $fields.each(function() {
      const $field = $(this);

      // Defer until after CiviCRM's own Select2 initialization completes.
      setTimeout(function() {
        const instance = $field.data('select2');

        if (instance) {
          // Grab contact ID from the contribution form — it's either in a
          // hidden field or available from the form's cid URL parameter.
          const contactId = $('input[name="contact_id"]').val() || new URLSearchParams(window.location.search).get('cid') || null;

          if (contactId) {
            const originalData = instance.opts.ajax.data;
            instance.opts.ajax.data = function(term, page) {
              const data = originalData ? originalData.call(this, term, page) : {};

              // Parse the existing params JSON string, inject contact_id,
              // then re-serialize so filters arrive inside params consistently.
              try {
                const params = JSON.parse(data.params || '{}');
                params.filters = params.filters || {};
                params.filters.contact_id = contactId;
                data.params = JSON.stringify(params);
              }
              catch (e) {
                console.error('ACHOffline: Failed to inject contact_id into params', e);
              }
              console.log('Autocomplete AJAX params:', JSON.stringify(data));
              return data;
            };
          }

          // Directly mutate the initialized Select2 v3 instance options
          // so minimumInputLength takes effect without reinitializing.
          instance.opts.minimumInputLength = 0;

          // Open dropdown on focus.
          $field.off('focus.achoffline').on('focus.achoffline', function() {
            $field.select2('open');
          });

          // Hide the search box inside the dropdown since the list is
          // short enough that searching adds no value.
          $field.off('select2-open.achoffline').on('select2-open.achoffline', function() {
            $('#select2-drop .select2-search').hide();
          });
        }
      }, 0);
    });
  }

  // crmLoad fires when CiviCRM injects content into the DOM,
  // including contribution edit modals.
  $(document).on('crmLoad', function(event) {
    initBankAccountSelect($(event.target));
  });

  // Also run on initial page load for non-modal forms.
  initBankAccountSelect($(document));
});