SELECT
  oi.*,
  DATE_FORMAT(oi.dt_created, '%d/%m/%Y %H:%i:%s') as dtCreated,
  CONCAT('R$ ', FORMAT(oi.vl_price, 2, 'pt_BR')) as unitPrice,
  CONCAT('R$ ', FORMAT(oi.vl_total, 2, 'pt_BR')) as total,
  st.nr_step_order,
  st.ds_tag as status_tag,
  st.ds_title as status_title,
  ex.ds_key as executionKey,
  trk.dt_track as dt_step_entered,
  prd.do_requires_preparation,
  ord.nr_tablenumber,
  ord.tx_delivery_address
FROM CTP_ORDER_ITEM oi
LEFT JOIN CTP_ORDER ord 
  ON ord.id_ctp_order = oi.id_ctp_order
LEFT JOIN BPM_EXECUTION ex 
  ON ex.ds_reference_entity_name = 'CTP_ORDER_ITEM' 
  AND ex.id_reference_entity_id = oi.id_ctp_order_item
LEFT JOIN BPM_STEP st 
  ON st.id_bpm_step = ex.id_bpm_step_current
LEFT JOIN (
  SELECT id_bpm_execution, id_bpm_step, MAX(dt_track) AS dt_track
  FROM BPM_STEP_TRACKING
  GROUP BY id_bpm_execution, id_bpm_step
) trk
  ON trk.id_bpm_execution = ex.id_bpm_execution
  AND trk.id_bpm_step = ex.id_bpm_step_current
LEFT JOIN CTP_PRODUCT prd 
  ON prd.id_ctp_product = oi.id_ctp_product
ORDER BY oi.dt_created DESC

