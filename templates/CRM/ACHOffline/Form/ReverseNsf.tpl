<div class="crm-block crm-form-block crm-achoffline-reverse-nsf-form-block">
  <div class="help">
    {ts domain="achoffline"}Each contribution is cancelled and reissued as a new Pending contribution. The NSF fee (0 for none) is added to each reissue. A reissue that is returned again gets no new fee and is not redrafted; it must be paid by card.{/ts}
  </div>

  <table class="selector row-highlight">
    <thead>
      <tr>
        <th>{ts domain="achoffline"}ID{/ts}</th>
        <th>{ts domain="achoffline"}Contact{/ts}</th>
        <th>{ts domain="achoffline"}Amount{/ts}</th>
        <th>{ts domain="achoffline"}Date{/ts}</th>
        <th>{ts domain="achoffline"}Status{/ts}</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      {foreach from=$rows item=row}
        <tr class="{if $row.skip_reason}disabled{/if}">
          <td>{$row.id}</td>
          <td>{$row.contact|escape}</td>
          <td>{$row.amount}</td>
          <td>{$row.receive_date}</td>
          <td>{$row.status|escape}</td>
          <td>{if $row.skip_reason}<em>{ts domain="achoffline"}Will be skipped:{/ts} {$row.skip_reason|escape}</em>{elseif $row.repeat_return}<em>{ts domain="achoffline"}No fee (repeat return, card only){/ts}</em>{/if}</td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  {if $eligibleCount}
    <table class="form-layout-compressed">
      <tr>
        <td class="label">{$form.fee_amount.label}</td>
        <td>{$form.fee_amount.html}
          {if !$firstReturnCount}
            <div class="description">{ts domain="achoffline"}All selected contributions are repeat returns, so no fee is added.{/ts}</div>
          {elseif $firstReturnCount < $eligibleCount}
            <div class="description">{ts domain="achoffline" 1=$firstReturnCount}Applies to the %1 first return(s) only.{/ts}</div>
          {/if}
        </td>
      </tr>
      <tr>
        <td class="label">{$form.reason.label}</td>
        <td>{$form.reason.html}</td>
      </tr>
    </table>
  {else}
    <div class="messages status no-popup">
      {ts domain="achoffline"}None of the selected contributions can be reversed.{/ts}
    </div>
  {/if}

  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
</div>
