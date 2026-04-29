SELECT
  ord.*,
  IF(ord.nr_tablenumber > 0, CONCAT('MESA ', ord.nr_tablenumber), 'DELIVERY') as tableNumber,
  IF(ord.nr_comanda IS NOT NULL, CONCAT('COMANDA ', ord.nr_comanda), NULL) as comandaLabel,
  DATE_FORMAT(ord.dt_created, '%d/%m/%Y %H:%i:%s') as dtCreated,
  CONCAT('R$ ', FORMAT(ord.vl_amount, 2, 'pt_BR')) as vlAmount,
  CONCAT('R$ ', FORMAT(ord.vl_discount, 2, 'pt_BR')) as vlDiscount,
  st.ds_tag as status_tag,
  st.ds_title as status_title,
  ex.ds_key as executionKey
FROM CTP_ORDER ord
LEFT JOIN BPM_EXECUTION ex ON ex.id_reference_entity_id = ord.id_ctp_order AND ex.ds_reference_entity_name='CTP_ORDER'
LEFT JOIN BPM_STEP st ON st.id_bpm_step = ex.id_bpm_step_current
ORDER BY ord.dt_created DESC
