<?php
/** @var array $booking */
?>
<div class="vb-booking-fields">
  <label>Type<select class="input" name="booking_type"><?php foreach(['flight'=>'Flight','lodging'=>'Lodging','transport'=>'Transportation','event'=>'Event / ticket','restaurant'=>'Restaurant','activity'=>'Activity','document'=>'Document / requirement','other'=>'Other'] as $v=>$l):?><option value="<?=$v?>" <?=($booking['booking_type']??'other')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></label>
  <label class="wide">Booking / reservation name<input class="input" name="title" maxlength="180" required value="<?=e((string)($booking['title']??''))?>" placeholder="Flight PHX → SJD, Hotel, dinner reservation…"></label>
  <label>Provider<input class="input" name="provider_name" maxlength="180" value="<?=e((string)($booking['provider_name']??''))?>" placeholder="Airline, hotel, restaurant…"></label>
  <label>Confirmation code<input class="input" name="confirmation_code" maxlength="120" value="<?=e((string)($booking['confirmation_code']??''))?>" autocomplete="off"></label>
  <label>Status<select class="input" name="status"><?php foreach(['unbooked'=>'Unbooked','ready_to_book'=>'Ready to book','booked'=>'Booked','confirmed'=>'Confirmed','changed'=>'Changed','cancelled'=>'Cancelled'] as $v=>$l):?><option value="<?=$v?>" <?=($booking['status']??'unbooked')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></label>
  <label>Payment<select class="input" name="payment_status"><?php foreach(['unknown'=>'Unknown','unpaid'=>'Unpaid','deposit_paid'=>'Deposit paid','paid'=>'Paid','refunded'=>'Refunded'] as $v=>$l):?><option value="<?=$v?>" <?=($booking['payment_status']??'unknown')===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></label>
  <label>Amount<input class="input" type="number" min="0" step="0.01" name="amount" value="<?=e(($booking['amount']??null)!==null?(string)$booking['amount']:'')?>"></label>
  <label>Currency<input class="input" name="currency" maxlength="3" value="<?=e((string)($booking['currency']??'USD'))?>"></label>
  <label>Starts<input class="input" type="datetime-local" name="starts_at" value="<?=e(vb_booking_input_dt($booking['starts_at']??''))?>"></label>
  <label>Ends<input class="input" type="datetime-local" name="ends_at" value="<?=e(vb_booking_input_dt($booking['ends_at']??''))?>"></label>
  <label>Cancellation deadline<input class="input" type="datetime-local" name="cancellation_deadline" value="<?=e(vb_booking_input_dt($booking['cancellation_deadline']??''))?>"></label>
  <label>Check-in opens<input class="input" type="datetime-local" name="checkin_opens_at" value="<?=e(vb_booking_input_dt($booking['checkin_opens_at']??''))?>"></label>
  <label class="wide">Provider URL<input class="input" type="url" name="provider_url" maxlength="1500" value="<?=e((string)($booking['provider_url']??''))?>" placeholder="https://…"></label>
  <label class="wide">Notes<textarea class="input" name="notes" rows="3" maxlength="4000" placeholder="Cancellation terms, balance due, seat, room, reminder…"><?=e((string)($booking['notes']??''))?></textarea></label>
</div>
