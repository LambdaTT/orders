<MainQuery>
SELECT
  ord.id_ord_order,
  ord.ds_key,
  ord.dt_created,
  ord.dt_updated,
  ord.nr_tablenumber,
  ord.nr_comanda,
  ord.id_bpm_execution,
  ord.vl_amount,
  ord.tx_delivery_address,
  IF(ord.nr_tablenumber > 0, CONCAT("MESA ", ord.nr_tablenumber), "DELIVERY") as tableNumber,
  IF(ord.nr_comanda IS NOT NULL, CONCAT("COMANDA ", ord.nr_comanda), NULL) as comandaLabel,
  DATE_FORMAT(ord.dt_created, '%d/%m/%Y %H:%i:%s') as dtCreated,
  CONCAT('R$ ', FORMAT(ord.vl_amount, 2, 'pt_BR')) as vlAmount,

  ost.id_bpm_step,
  ost.ds_tag as status_tag,
  ost.ds_title as status_title,

  oex.ds_key as executionKey,

  oi.id_ord_order_item,
  oi.ds_key              as item_ds_key,
  oi.dt_created           as item_dt_created,
  oi.dt_updated           as item_dt_updated,
  oi.id_prd_product       as item_id_prd_product,
  oi.ds_product_representation as item_ds_product_representation,
  oi.qt_quantity          as item_qt_quantity,
  oi.vl_price             as item_vl_price,
  oi.vl_total             as item_vl_total,
  DATE_FORMAT(oi.dt_created, '%d/%m/%Y %H:%i:%s') as item_dtCreated,
  CONCAT('R$ ', FORMAT(oi.vl_price, 2, 'pt_BR'))  as item_unitPrice,
  CONCAT('R$ ', FORMAT(oi.vl_total, 2, 'pt_BR'))  as item_vlTotal,

  ist.nr_step_order       as item_nr_step_order,
  ist.ds_tag              as item_status_tag,
  ist.ds_title            as item_status_title,
  ist.id_bpm_step         as item_id_bpm_step,

  iex.ds_key              as item_executionKey,

  itrk.dt_track           as item_dt_step_entered,

  prd.do_requires_preparation as item_do_requires_preparation

FROM ORD_ORDER ord
LEFT JOIN BPM_EXECUTION oex ON oex.id_reference_entity_id = ord.id_ord_order AND oex.ds_reference_entity_name='ORD_ORDER'
LEFT JOIN BPM_STEP ost ON ost.id_bpm_step = oex.id_bpm_step_current
LEFT JOIN ORD_ORDER_ITEM oi ON oi.id_ord_order = ord.id_ord_order
LEFT JOIN BPM_EXECUTION iex
  ON iex.ds_reference_entity_name = 'ORD_ORDER_ITEM'
  AND iex.id_reference_entity_id = oi.id_ord_order_item
LEFT JOIN BPM_STEP ist ON ist.id_bpm_step = iex.id_bpm_step_current
LEFT JOIN (
  SELECT id_bpm_execution, id_bpm_step, MAX(dt_track) AS dt_track
  FROM BPM_STEP_TRACKING
  GROUP BY id_bpm_execution, id_bpm_step
) itrk
  ON itrk.id_bpm_execution = iex.id_bpm_execution
  AND itrk.id_bpm_step = iex.id_bpm_step_current
LEFT JOIN CTP_PRODUCT prd ON prd.id_prd_product = oi.id_prd_product

ORDER BY ord.id_ord_order DESC, oi.dt_created DESC
</MainQuery>
