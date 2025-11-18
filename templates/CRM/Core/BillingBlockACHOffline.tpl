{crmScope extensionKey='ACHOffline'}
{if isset($form.payment_token)}
  <div class="crm-section {$form.payment_token.name}-section">
    <div class="label">
        {$form.payment_token.label}
    </div>
    <div class="content">
        {$form.payment_token.html}
    </div>
    <div class="clear"></div>
  </div>
{/if}
{capture assign="CheckImage"}{crmResURL ext="ACHOffline" file="images/USD_check_500x.jpg"}{/capture}
{capture assign="CheckNotes"}{ts domain='ACHOffline'}You can find your Bank Routing Number and Bank Account number by inspecting a check.{/ts}{/capture}
{literal}
  <script>

    (function() {
      // Re-prep form when we've loaded a new payproc via ajax or via webform
      document.addEventListener('ajaxComplete', (event, xhr, settings) => {
        if (CRM.payment.isAJAXPaymentForm(settings.url)) {
          CRM.payment.debugging('ACHOffline', 'triggered via ajax');
          addACHInstructions();
          savedAccountSelector();
        }
      });

      // Run immediately if DOM is ready, otherwise wait
      if (document.readyState !== 'loading') {
        addACHInstructions();
        savedAccountSelector();
        updateACHFields();
      } else {
        document.addEventListener('DOMContentLoaded', (event) => {
          console.log('DOMContentLoaded from ACHOffline');
          addACHInstructions();
          savedAccountSelector();
          updateACHFields();
        });
      }

      function savedAccountSelector() {
        const paymentTokenElement = document.querySelector('select#payment_token');
        paymentTokenElement.addEventListener('change', (event) => {
          updateACHFields();
        });
      }

      function addACHInstructions() {
        const checkNotes = document.querySelector('div.ach_instructions-section');
        if (checkNotes === null) {
          const fragment = document.createDocumentFragment();
          const crmSection = fragment.appendChild(document.createElement("div"));
          const crmSectionLabel = document.createElement("div");
          const crmSectionContent = document.createElement("div");
          const crmSectionClear = document.createElement("div");
          crmSection.classList.add('crm-section');
          crmSection.classList.add('ach_instructions-section');
          crmSectionLabel.classList.add('label');
          crmSectionLabel.innerHTML = '<em>{/literal}{$CheckNotes}{literal}</em>';
          crmSectionContent.classList.add('content');
          crmSectionContent.innerHTML = '<img width="500" height="303" src="{/literal}{$CheckImage}{literal}">';
          crmSectionClear.classList.add('clear');
          crmSection.appendChild(crmSectionLabel);
          crmSection.appendChild(crmSectionContent);
          crmSection.appendChild(crmSectionClear);

          const achInfo = document.querySelector('div.ach_info-section');
          if (achInfo.nextElementSibling) {
            achInfo.parentNode.insertBefore(fragment, achInfo.nextElementSibling);
          } else {
            achInfo.parentNode.appendChild(fragment);
          }
        }
      }

      function updateACHFields() {
        const paymentTokenElement = document.querySelector('select#payment_token');
        if (paymentTokenElement.value == 0) {
          document.querySelector('input#bank_name').classList.add('required');
          document.querySelector('input#bank_account_number').classList.add('required');
          document.querySelector('input#bank_identification_number').classList.add('required');
          document.querySelector('div.ach_info-section').hidden = false;
        }
        else {
          document.querySelector('div.ach_info-section').hidden = true;
          document.querySelector('input#bank_name').classList.remove('required');
          document.querySelector('input#bank_account_number').classList.remove('required');
          document.querySelector('input#bank_identification_number').classList.remove('required');
        }
      }
    })();
  </script>
{/literal}

{/crmScope}