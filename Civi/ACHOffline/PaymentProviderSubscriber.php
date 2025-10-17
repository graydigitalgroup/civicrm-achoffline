<?php

namespace Civi\ACHOffline;

use Civi\Core\Service\AutoSubscriber;

/**
 * Adds custom logic to Contributions.
 */
class PaymentProviderSubscriber extends AutoSubscriber {

  /**
   * Binds function to Civi events.
   */
  public static function getSubscribedEvents() {
    return [
      '&hook_civicrm_buildForm'         => ['onBuildForm'],
      '&hook_civicrm_alterTemplateFile' => ['onAlterTemplateFile'],
    ];
  }

  /**
   * Intercept form functions.
   *
   * @param string $formName
   * @param \CRM_Core_Form $form
   */
  public function onBuildForm(string $formName, \CRM_Core_Form &$form) {
    if ('CRM_Admin_Form_PaymentProcessor' === $formName) {
      $paymentProcessorDAO = $this->getPaymentProcessorDAO($form);
      if ($paymentProcessorDAO) {
        if ('Payment_ACHOffline' === $paymentProcessorDAO->class_name) {
          $processor_fields = ['user_name', 'password', 'signature', 'url_site', 'url_recur', 'url_api', 'url_button', 'subject'];
          foreach( $processor_fields as $field ) {
            if ($form->elementExists($field)) {
              $form->removeElement($field);
            }

            // Try to remove the test fields as well.
            if ($form->elementExists('test_' . $field)) {
              $form->removeElement('test_' . $field);
            }
          }

          // Remove the accepts CC checkboxes since this isn't a CC payment processor.
          if ($form->elementExists('accept_credit_cards')) {
            $form->removeElement('accept_credit_cards');
          }

          foreach( $form->_formRules as $key => $formRule) {
            if (['CRM_Admin_Form_PaymentProcessor', 'formRule'] == $formRule[0]) {
              unset($form->_formRules[$key]);
            }
          }
        }
      }
    }
  }

  /**
   * Intercept form functions.
   *
   * @param string $formName The name of the form.
   * @param \CRM_Core_Form $form The form object.
   * @param string $context A page or form.
   * @param string $tplName - The file name of the tpl.
   */
  public function onAlterTemplateFile($formName, &$form, $context, &$tplName) {
    if ('CRM_Admin_Form_PaymentProcessor' === $formName) {
      $paymentProcessorDAO = $this->getPaymentProcessorDAO($form);
      if ($paymentProcessorDAO) {
        if ('Payment_ACHOffline' === $paymentProcessorDAO->class_name) {
          $tplName = 'CRM/Admin/Form/ACHOffline-PaymentProcessor.tpl';
        }
      }
    }
  }

  /**
   * @param \CRM_Core_Form $form The form object.
   *
   * @return \CRM_Financial_DAO_PaymentProcessorType|null
   */
  public function getPaymentProcessorDAO(\CRM_Core_Form $form) :\CRM_Financial_DAO_PaymentProcessorType|null {
    if ($form->_id) {
      $paymentProcessorType = \CRM_Utils_Request::retrieve('pp', 'String', $form, FALSE, NULL);
      if (!$paymentProcessorType) {
        $paymentProcessorType = \CRM_Core_DAO::getFieldValue(
          'CRM_Financial_DAO_PaymentProcessor',
          $form->_id,
          'payment_processor_type_id'
        );
      }
    } else {
      $paymentProcessorType = \CRM_Utils_Request::retrieve('pp', 'String', $form, FALSE, NULL);
    }
    if ($paymentProcessorType) {
      $paymentProcessorDAO = new \CRM_Financial_DAO_PaymentProcessorType();
      $paymentProcessorDAO->id = $paymentProcessorType;
      $paymentProcessorDAO->find(TRUE);
      return $paymentProcessorDAO;
    }
    return null;
  }

}