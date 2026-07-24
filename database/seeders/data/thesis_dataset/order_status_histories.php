<?php

// Generated for ThesisDatasetSeeder — do not edit by hand.

return array (
  0 => 
  array (
    'id' => '1',
    'order_id' => '1',
    'status_id' => '1',
    'changed_by_user_id' => '10',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 09:20:10',
  ),
  1 => 
  array (
    'id' => '2',
    'order_id' => '1',
    'status_id' => '2',
    'changed_by_user_id' => '2',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 01","phone":"081100000001","user_id":2,"driver_id":1,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Vario 125 New","vehicle_plate":"H 1001 AA"}}',
    'created_at' => '2026-07-15 09:20:23',
  ),
  2 => 
  array (
    'id' => '3',
    'order_id' => '1',
    'status_id' => '4',
    'changed_by_user_id' => '2',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-15 09:23:27',
  ),
  3 => 
  array (
    'id' => '4',
    'order_id' => '1',
    'status_id' => '6',
    'changed_by_user_id' => '2',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 09:24:12',
  ),
  4 => 
  array (
    'id' => '5',
    'order_id' => '1',
    'status_id' => '7',
    'changed_by_user_id' => '2',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-15 09:49:09',
  ),
  5 => 
  array (
    'id' => '6',
    'order_id' => '1',
    'status_id' => '8',
    'changed_by_user_id' => '2',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-15 09:49:24',
  ),
  6 => 
  array (
    'id' => '7',
    'order_id' => '1',
    'status_id' => '9',
    'changed_by_user_id' => '2',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 09:50:09',
  ),
  7 => 
  array (
    'id' => '8',
    'order_id' => '2',
    'status_id' => '1',
    'changed_by_user_id' => '11',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 10:55:33',
  ),
  8 => 
  array (
    'id' => '9',
    'order_id' => '2',
    'status_id' => '2',
    'changed_by_user_id' => '9',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Responden Lama 01","phone":"081100000002","user_id":9,"driver_id":2,"vehicle_type":"Motor Manual","vehicle_brand":"Honda","vehicle_model":"Supra X 125","vehicle_plate":"H 1002 AA"}}',
    'created_at' => '2026-07-15 10:55:43',
  ),
  9 => 
  array (
    'id' => '10',
    'order_id' => '2',
    'status_id' => '3',
    'changed_by_user_id' => '9',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":3}',
    'created_at' => '2026-07-15 11:00:45',
  ),
  10 => 
  array (
    'id' => '11',
    'order_id' => '2',
    'status_id' => '5',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 11:21:24',
  ),
  11 => 
  array (
    'id' => '12',
    'order_id' => '2',
    'status_id' => '6',
    'changed_by_user_id' => '9',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 11:21:25',
  ),
  12 => 
  array (
    'id' => '13',
    'order_id' => '2',
    'status_id' => '7',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 11:24:20',
  ),
  13 => 
  array (
    'id' => '14',
    'order_id' => '2',
    'status_id' => '8',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 11:24:35',
  ),
  14 => 
  array (
    'id' => '15',
    'order_id' => '2',
    'status_id' => '9',
    'changed_by_user_id' => '9',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 11:25:20',
  ),
  15 => 
  array (
    'id' => '16',
    'order_id' => '3',
    'status_id' => '1',
    'changed_by_user_id' => '3',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 11:56:18',
  ),
  16 => 
  array (
    'id' => '17',
    'order_id' => '3',
    'status_id' => '2',
    'changed_by_user_id' => '9',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Responden Lama 01","phone":"081100000002","user_id":9,"driver_id":2,"vehicle_type":"Motor Manual","vehicle_brand":"Honda","vehicle_model":"Supra X 125","vehicle_plate":"H 1002 AA"}}',
    'created_at' => '2026-07-15 11:56:54',
  ),
  17 => 
  array (
    'id' => '18',
    'order_id' => '3',
    'status_id' => '4',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:03:40',
  ),
  18 => 
  array (
    'id' => '19',
    'order_id' => '3',
    'status_id' => '6',
    'changed_by_user_id' => '9',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:04:25',
  ),
  19 => 
  array (
    'id' => '20',
    'order_id' => '3',
    'status_id' => '7',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:17:27',
  ),
  20 => 
  array (
    'id' => '21',
    'order_id' => '3',
    'status_id' => '8',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:17:42',
  ),
  21 => 
  array (
    'id' => '22',
    'order_id' => '3',
    'status_id' => '9',
    'changed_by_user_id' => '9',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:20:30',
  ),
  22 => 
  array (
    'id' => '23',
    'order_id' => '4',
    'status_id' => '1',
    'changed_by_user_id' => '12',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 12:21:44',
  ),
  23 => 
  array (
    'id' => '24',
    'order_id' => '4',
    'status_id' => '2',
    'changed_by_user_id' => '9',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Responden Lama 01","phone":"081100000002","user_id":9,"driver_id":2,"vehicle_type":"Motor Manual","vehicle_brand":"Honda","vehicle_model":"Supra X 125","vehicle_plate":"H 1002 AA"}}',
    'created_at' => '2026-07-15 12:21:54',
  ),
  24 => 
  array (
    'id' => '25',
    'order_id' => '4',
    'status_id' => '4',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:25:05',
  ),
  25 => 
  array (
    'id' => '26',
    'order_id' => '4',
    'status_id' => '6',
    'changed_by_user_id' => '9',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:28:15',
  ),
  26 => 
  array (
    'id' => '27',
    'order_id' => '4',
    'status_id' => '7',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:42:14',
  ),
  27 => 
  array (
    'id' => '28',
    'order_id' => '4',
    'status_id' => '8',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:42:29',
  ),
  28 => 
  array (
    'id' => '29',
    'order_id' => '4',
    'status_id' => '9',
    'changed_by_user_id' => '9',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 12:43:59',
  ),
  29 => 
  array (
    'id' => '30',
    'order_id' => '5',
    'status_id' => '1',
    'changed_by_user_id' => '13',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 13:48:53',
  ),
  30 => 
  array (
    'id' => '31',
    'order_id' => '5',
    'status_id' => '2',
    'changed_by_user_id' => '9',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Responden Lama 01","phone":"081100000002","user_id":9,"driver_id":2,"vehicle_type":"Motor Manual","vehicle_brand":"Honda","vehicle_model":"Supra X 125","vehicle_plate":"H 1002 AA"}}',
    'created_at' => '2026-07-15 13:52:37',
  ),
  31 => 
  array (
    'id' => '32',
    'order_id' => '5',
    'status_id' => '4',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-15 13:54:41',
  ),
  32 => 
  array (
    'id' => '33',
    'order_id' => '5',
    'status_id' => '6',
    'changed_by_user_id' => '9',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 13:55:26',
  ),
  33 => 
  array (
    'id' => '34',
    'order_id' => '6',
    'status_id' => '1',
    'changed_by_user_id' => '14',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 13:59:36',
  ),
  34 => 
  array (
    'id' => '35',
    'order_id' => '5',
    'status_id' => '7',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-15 14:07:20',
  ),
  35 => 
  array (
    'id' => '36',
    'order_id' => '5',
    'status_id' => '8',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-15 14:07:35',
  ),
  36 => 
  array (
    'id' => '37',
    'order_id' => '5',
    'status_id' => '9',
    'changed_by_user_id' => '9',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 14:08:20',
  ),
  37 => 
  array (
    'id' => '38',
    'order_id' => '6',
    'status_id' => '2',
    'changed_by_user_id' => '2',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 01","phone":"081100000001","user_id":2,"driver_id":1,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Vario 125 New","vehicle_plate":"H 1001 AA"}}',
    'created_at' => '2026-07-15 14:06:01',
  ),
  38 => 
  array (
    'id' => '39',
    'order_id' => '6',
    'status_id' => '3',
    'changed_by_user_id' => '2',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":11}',
    'created_at' => '2026-07-15 14:06:40',
  ),
  39 => 
  array (
    'id' => '40',
    'order_id' => '6',
    'status_id' => '5',
    'changed_by_user_id' => '2',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:16:04',
  ),
  40 => 
  array (
    'id' => '41',
    'order_id' => '6',
    'status_id' => '6',
    'changed_by_user_id' => '2',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:16:07',
  ),
  41 => 
  array (
    'id' => '42',
    'order_id' => '6',
    'status_id' => '7',
    'changed_by_user_id' => '2',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:17:18',
  ),
  42 => 
  array (
    'id' => '43',
    'order_id' => '6',
    'status_id' => '8',
    'changed_by_user_id' => '2',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:18:47',
  ),
  43 => 
  array (
    'id' => '44',
    'order_id' => '6',
    'status_id' => '9',
    'changed_by_user_id' => '2',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:19:50',
  ),
  44 => 
  array (
    'id' => '45',
    'order_id' => '7',
    'status_id' => '1',
    'changed_by_user_id' => '15',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 14:25:30',
  ),
  45 => 
  array (
    'id' => '46',
    'order_id' => '7',
    'status_id' => '2',
    'changed_by_user_id' => '9',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Responden Lama 01","phone":"081100000002","user_id":9,"driver_id":2,"vehicle_type":"Motor Manual","vehicle_brand":"Honda","vehicle_model":"Supra X 125","vehicle_plate":"H 1002 AA"}}',
    'created_at' => '2026-07-15 14:29:22',
  ),
  46 => 
  array (
    'id' => '47',
    'order_id' => '7',
    'status_id' => '3',
    'changed_by_user_id' => '9',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":13}',
    'created_at' => '2026-07-15 14:31:30',
  ),
  47 => 
  array (
    'id' => '48',
    'order_id' => '7',
    'status_id' => '5',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:41:14',
  ),
  48 => 
  array (
    'id' => '49',
    'order_id' => '7',
    'status_id' => '6',
    'changed_by_user_id' => '9',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:41:18',
  ),
  49 => 
  array (
    'id' => '50',
    'order_id' => '7',
    'status_id' => '7',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:43:21',
  ),
  50 => 
  array (
    'id' => '51',
    'order_id' => '7',
    'status_id' => '8',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:43:36',
  ),
  51 => 
  array (
    'id' => '52',
    'order_id' => '7',
    'status_id' => '9',
    'changed_by_user_id' => '9',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 14:44:21',
  ),
  52 => 
  array (
    'id' => '53',
    'order_id' => '8',
    'status_id' => '1',
    'changed_by_user_id' => '5',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 17:32:20',
  ),
  53 => 
  array (
    'id' => '54',
    'order_id' => '8',
    'status_id' => '2',
    'changed_by_user_id' => '9',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Responden Lama 01","phone":"081100000002","user_id":9,"driver_id":2,"vehicle_type":"Motor Manual","vehicle_brand":"Honda","vehicle_model":"Supra X 125","vehicle_plate":"H 1002 AA"}}',
    'created_at' => '2026-07-15 17:32:33',
  ),
  54 => 
  array (
    'id' => '55',
    'order_id' => '8',
    'status_id' => '3',
    'changed_by_user_id' => '9',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code": "MERCHANT_OPEN_CONFIRMED", "pickup_location_id": 16}',
    'created_at' => '2026-07-15 17:33:01',
  ),
  55 => 
  array (
    'id' => '56',
    'order_id' => '8',
    'status_id' => '5',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:00:22',
  ),
  56 => 
  array (
    'id' => '57',
    'order_id' => '8',
    'status_id' => '6',
    'changed_by_user_id' => '9',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:00:24',
  ),
  57 => 
  array (
    'id' => '58',
    'order_id' => '8',
    'status_id' => '7',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:02:12',
  ),
  58 => 
  array (
    'id' => '59',
    'order_id' => '8',
    'status_id' => '8',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:02:27',
  ),
  59 => 
  array (
    'id' => '60',
    'order_id' => '8',
    'status_id' => '9',
    'changed_by_user_id' => '9',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:03:12',
  ),
  60 => 
  array (
    'id' => '61',
    'order_id' => '9',
    'status_id' => '1',
    'changed_by_user_id' => '16',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 18:04:33',
  ),
  61 => 
  array (
    'id' => '62',
    'order_id' => '9',
    'status_id' => '2',
    'changed_by_user_id' => '9',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Responden Lama 01","phone":"081100000002","user_id":9,"driver_id":2,"vehicle_type":"Motor Manual","vehicle_brand":"Honda","vehicle_model":"Supra X 125","vehicle_plate":"H 1002 AA"}}',
    'created_at' => '2026-07-15 18:04:43',
  ),
  62 => 
  array (
    'id' => '63',
    'order_id' => '9',
    'status_id' => '3',
    'changed_by_user_id' => '9',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":18}',
    'created_at' => '2026-07-15 18:08:02',
  ),
  63 => 
  array (
    'id' => '64',
    'order_id' => '9',
    'status_id' => '5',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:18:59',
  ),
  64 => 
  array (
    'id' => '65',
    'order_id' => '9',
    'status_id' => '6',
    'changed_by_user_id' => '9',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:19:00',
  ),
  65 => 
  array (
    'id' => '66',
    'order_id' => '9',
    'status_id' => '7',
    'changed_by_user_id' => '9',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:21:06',
  ),
  66 => 
  array (
    'id' => '67',
    'order_id' => '9',
    'status_id' => '8',
    'changed_by_user_id' => '9',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:21:21',
  ),
  67 => 
  array (
    'id' => '68',
    'order_id' => '9',
    'status_id' => '9',
    'changed_by_user_id' => '9',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:22:28',
  ),
  68 => 
  array (
    'id' => '69',
    'order_id' => '10',
    'status_id' => '1',
    'changed_by_user_id' => '17',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 18:36:40',
  ),
  69 => 
  array (
    'id' => '70',
    'order_id' => '10',
    'status_id' => '2',
    'changed_by_user_id' => '2',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 01","phone":"081100000001","user_id":2,"driver_id":1,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Vario 125 New","vehicle_plate":"H 1001 AA"}}',
    'created_at' => '2026-07-15 18:36:51',
  ),
  70 => 
  array (
    'id' => '71',
    'order_id' => '10',
    'status_id' => '3',
    'changed_by_user_id' => '2',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":20}',
    'created_at' => '2026-07-15 18:37:32',
  ),
  71 => 
  array (
    'id' => '72',
    'order_id' => '10',
    'status_id' => '5',
    'changed_by_user_id' => '2',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:52:07',
  ),
  72 => 
  array (
    'id' => '73',
    'order_id' => '10',
    'status_id' => '6',
    'changed_by_user_id' => '2',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:52:19',
  ),
  73 => 
  array (
    'id' => '74',
    'order_id' => '10',
    'status_id' => '7',
    'changed_by_user_id' => '2',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:53:39',
  ),
  74 => 
  array (
    'id' => '75',
    'order_id' => '10',
    'status_id' => '8',
    'changed_by_user_id' => '2',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:53:54',
  ),
  75 => 
  array (
    'id' => '76',
    'order_id' => '10',
    'status_id' => '9',
    'changed_by_user_id' => '2',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 18:54:39',
  ),
  76 => 
  array (
    'id' => '77',
    'order_id' => '11',
    'status_id' => '1',
    'changed_by_user_id' => '19',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 17:48:09',
  ),
  77 => 
  array (
    'id' => '78',
    'order_id' => '12',
    'status_id' => '1',
    'changed_by_user_id' => '19',
    'note' => 'Order kurir dibuat oleh customer melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 17:53:13',
  ),
  78 => 
  array (
    'id' => '79',
    'order_id' => '11',
    'status_id' => '10',
    'changed_by_user_id' => '19',
    'note' => 'Pesanan tidak jadi',
    'metadata' => '{"cancellation_penalty":0}',
    'created_at' => '2026-07-15 17:54:09',
  ),
  79 => 
  array (
    'id' => '80',
    'order_id' => '12',
    'status_id' => '2',
    'changed_by_user_id' => '20',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 04","phone":"081100000004","user_id":20,"driver_id":4,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Beat Street","vehicle_plate":"H 1004 AA"}}',
    'created_at' => '2026-07-15 18:20:17',
  ),
  80 => 
  array (
    'id' => '81',
    'order_id' => '12',
    'status_id' => '4',
    'changed_by_user_id' => '20',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"COURIER"}',
    'created_at' => '2026-07-15 18:22:21',
  ),
  81 => 
  array (
    'id' => '82',
    'order_id' => '12',
    'status_id' => '5',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"COURIER"}',
    'created_at' => '2026-07-15 18:23:51',
  ),
  82 => 
  array (
    'id' => '83',
    'order_id' => '12',
    'status_id' => '6',
    'changed_by_user_id' => '20',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"COURIER"}',
    'created_at' => '2026-07-15 18:23:56',
  ),
  83 => 
  array (
    'id' => '84',
    'order_id' => '12',
    'status_id' => '7',
    'changed_by_user_id' => '20',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"COURIER"}',
    'created_at' => '2026-07-15 18:27:03',
  ),
  84 => 
  array (
    'id' => '85',
    'order_id' => '12',
    'status_id' => '8',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"COURIER"}',
    'created_at' => '2026-07-15 18:27:18',
  ),
  85 => 
  array (
    'id' => '86',
    'order_id' => '12',
    'status_id' => '9',
    'changed_by_user_id' => '20',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"COURIER"}',
    'created_at' => '2026-07-15 18:28:03',
  ),
  86 => 
  array (
    'id' => '87',
    'order_id' => '13',
    'status_id' => '1',
    'changed_by_user_id' => '19',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 18:30:01',
  ),
  87 => 
  array (
    'id' => '88',
    'order_id' => '13',
    'status_id' => '2',
    'changed_by_user_id' => '20',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 04","phone":"081100000004","user_id":20,"driver_id":4,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Beat Street","vehicle_plate":"H 1004 AA"}}',
    'created_at' => '2026-07-15 18:30:25',
  ),
  88 => 
  array (
    'id' => '89',
    'order_id' => '13',
    'status_id' => '4',
    'changed_by_user_id' => '20',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-15 18:34:43',
  ),
  89 => 
  array (
    'id' => '90',
    'order_id' => '13',
    'status_id' => '6',
    'changed_by_user_id' => '20',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 18:35:28',
  ),
  90 => 
  array (
    'id' => '91',
    'order_id' => '13',
    'status_id' => '7',
    'changed_by_user_id' => '20',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-15 18:38:01',
  ),
  91 => 
  array (
    'id' => '92',
    'order_id' => '13',
    'status_id' => '8',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-15 18:38:16',
  ),
  92 => 
  array (
    'id' => '93',
    'order_id' => '13',
    'status_id' => '9',
    'changed_by_user_id' => '20',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 18:39:01',
  ),
  93 => 
  array (
    'id' => '94',
    'order_id' => '14',
    'status_id' => '1',
    'changed_by_user_id' => '14',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 18:43:33',
  ),
  94 => 
  array (
    'id' => '95',
    'order_id' => '14',
    'status_id' => '2',
    'changed_by_user_id' => '20',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 04","phone":"081100000004","user_id":20,"driver_id":4,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Beat Street","vehicle_plate":"H 1004 AA"}}',
    'created_at' => '2026-07-15 18:44:15',
  ),
  95 => 
  array (
    'id' => '96',
    'order_id' => '14',
    'status_id' => '3',
    'changed_by_user_id' => '20',
    'note' => 'Driver menandai merchant tutup dan mulai memproses order Nitip.',
    'metadata' => '{"action_code":"MERCHANT_CLOSED_CONFIRMED","pickup_location_id":30}',
    'created_at' => '2026-07-15 18:45:04',
  ),
  96 => 
  array (
    'id' => '97',
    'order_id' => '14',
    'status_id' => '5',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:21:03',
  ),
  97 => 
  array (
    'id' => '98',
    'order_id' => '14',
    'status_id' => '6',
    'changed_by_user_id' => '20',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:21:08',
  ),
  98 => 
  array (
    'id' => '99',
    'order_id' => '14',
    'status_id' => '7',
    'changed_by_user_id' => '20',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:31:02',
  ),
  99 => 
  array (
    'id' => '100',
    'order_id' => '14',
    'status_id' => '8',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:31:17',
  ),
  100 => 
  array (
    'id' => '101',
    'order_id' => '14',
    'status_id' => '9',
    'changed_by_user_id' => '20',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:32:02',
  ),
  101 => 
  array (
    'id' => '102',
    'order_id' => '15',
    'status_id' => '1',
    'changed_by_user_id' => '15',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 19:33:59',
  ),
  102 => 
  array (
    'id' => '103',
    'order_id' => '15',
    'status_id' => '2',
    'changed_by_user_id' => '20',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 04","phone":"081100000004","user_id":20,"driver_id":4,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Beat Street","vehicle_plate":"H 1004 AA"}}',
    'created_at' => '2026-07-15 19:34:32',
  ),
  103 => 
  array (
    'id' => '104',
    'order_id' => '15',
    'status_id' => '3',
    'changed_by_user_id' => '20',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":32}',
    'created_at' => '2026-07-15 19:34:47',
  ),
  104 => 
  array (
    'id' => '105',
    'order_id' => '15',
    'status_id' => '5',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:58:17',
  ),
  105 => 
  array (
    'id' => '106',
    'order_id' => '15',
    'status_id' => '6',
    'changed_by_user_id' => '20',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:58:23',
  ),
  106 => 
  array (
    'id' => '107',
    'order_id' => '15',
    'status_id' => '7',
    'changed_by_user_id' => '20',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:59:18',
  ),
  107 => 
  array (
    'id' => '108',
    'order_id' => '15',
    'status_id' => '8',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 19:59:33',
  ),
  108 => 
  array (
    'id' => '109',
    'order_id' => '15',
    'status_id' => '9',
    'changed_by_user_id' => '20',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:00:47',
  ),
  109 => 
  array (
    'id' => '110',
    'order_id' => '16',
    'status_id' => '1',
    'changed_by_user_id' => '23',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 19:33:42',
  ),
  110 => 
  array (
    'id' => '111',
    'order_id' => '16',
    'status_id' => '2',
    'changed_by_user_id' => '2',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 01","phone":"081100000001","user_id":2,"driver_id":1,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Vario 125 New","vehicle_plate":"H 1001 AA"}}',
    'created_at' => '2026-07-15 19:34:25',
  ),
  111 => 
  array (
    'id' => '112',
    'order_id' => '16',
    'status_id' => '3',
    'changed_by_user_id' => '2',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":35}',
    'created_at' => '2026-07-15 19:40:30',
  ),
  112 => 
  array (
    'id' => '113',
    'order_id' => '17',
    'status_id' => '1',
    'changed_by_user_id' => '23',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 20:01:59',
  ),
  113 => 
  array (
    'id' => '114',
    'order_id' => '17',
    'status_id' => '2',
    'changed_by_user_id' => '20',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 04","phone":"081100000004","user_id":20,"driver_id":4,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Beat Street","vehicle_plate":"H 1004 AA"}}',
    'created_at' => '2026-07-15 20:03:00',
  ),
  114 => 
  array (
    'id' => '115',
    'order_id' => '17',
    'status_id' => '3',
    'changed_by_user_id' => '20',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":37}',
    'created_at' => '2026-07-15 20:03:07',
  ),
  115 => 
  array (
    'id' => '116',
    'order_id' => '17',
    'status_id' => '5',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:25:08',
  ),
  116 => 
  array (
    'id' => '117',
    'order_id' => '17',
    'status_id' => '6',
    'changed_by_user_id' => '20',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:25:11',
  ),
  117 => 
  array (
    'id' => '118',
    'order_id' => '17',
    'status_id' => '7',
    'changed_by_user_id' => '20',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:26:58',
  ),
  118 => 
  array (
    'id' => '119',
    'order_id' => '17',
    'status_id' => '8',
    'changed_by_user_id' => '20',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:27:13',
  ),
  119 => 
  array (
    'id' => '120',
    'order_id' => '17',
    'status_id' => '9',
    'changed_by_user_id' => '20',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:27:58',
  ),
  120 => 
  array (
    'id' => '121',
    'order_id' => '16',
    'status_id' => '5',
    'changed_by_user_id' => '2',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:00:53',
  ),
  121 => 
  array (
    'id' => '122',
    'order_id' => '16',
    'status_id' => '6',
    'changed_by_user_id' => '2',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:01:28',
  ),
  122 => 
  array (
    'id' => '123',
    'order_id' => '16',
    'status_id' => '7',
    'changed_by_user_id' => '2',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:05:57',
  ),
  123 => 
  array (
    'id' => '124',
    'order_id' => '16',
    'status_id' => '8',
    'changed_by_user_id' => '2',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:06:12',
  ),
  124 => 
  array (
    'id' => '125',
    'order_id' => '16',
    'status_id' => '9',
    'changed_by_user_id' => '2',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-15 20:07:45',
  ),
  125 => 
  array (
    'id' => '126',
    'order_id' => '18',
    'status_id' => '1',
    'changed_by_user_id' => '23',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 19:54:54',
  ),
  126 => 
  array (
    'id' => '127',
    'order_id' => '18',
    'status_id' => '10',
    'changed_by_user_id' => '23',
    'note' => 'Terlalu lama',
    'metadata' => '{"cancellation_penalty":0}',
    'created_at' => '2026-07-15 19:57:07',
  ),
  127 => 
  array (
    'id' => '128',
    'order_id' => '19',
    'status_id' => '1',
    'changed_by_user_id' => '23',
    'note' => 'Order kurir dibuat oleh customer melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 19:59:42',
  ),
  128 => 
  array (
    'id' => '129',
    'order_id' => '19',
    'status_id' => '10',
    'changed_by_user_id' => '23',
    'note' => 'Alamat salah',
    'metadata' => '{"cancellation_penalty":0}',
    'created_at' => '2026-07-15 20:01:54',
  ),
  129 => 
  array (
    'id' => '130',
    'order_id' => '20',
    'status_id' => '1',
    'changed_by_user_id' => '22',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 20:02:24',
  ),
  130 => 
  array (
    'id' => '131',
    'order_id' => '20',
    'status_id' => '2',
    'changed_by_user_id' => '18',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 03","phone":"081100000003","user_id":18,"driver_id":3,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Supra X","vehicle_plate":"H 1003 AA"}}',
    'created_at' => '2026-07-15 20:02:52',
  ),
  131 => 
  array (
    'id' => '132',
    'order_id' => '20',
    'status_id' => '4',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:07:07',
  ),
  132 => 
  array (
    'id' => '133',
    'order_id' => '20',
    'status_id' => '6',
    'changed_by_user_id' => '18',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:07:52',
  ),
  133 => 
  array (
    'id' => '134',
    'order_id' => '20',
    'status_id' => '7',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:13:21',
  ),
  134 => 
  array (
    'id' => '135',
    'order_id' => '20',
    'status_id' => '8',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:14:05',
  ),
  135 => 
  array (
    'id' => '136',
    'order_id' => '21',
    'status_id' => '1',
    'changed_by_user_id' => '23',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 20:04:31',
  ),
  136 => 
  array (
    'id' => '137',
    'order_id' => '21',
    'status_id' => '10',
    'changed_by_user_id' => '23',
    'note' => 'Berubah pikiran',
    'metadata' => '{"cancellation_penalty":0}',
    'created_at' => '2026-07-15 20:04:44',
  ),
  137 => 
  array (
    'id' => '138',
    'order_id' => '20',
    'status_id' => '9',
    'changed_by_user_id' => '18',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:14:50',
  ),
  138 => 
  array (
    'id' => '139',
    'order_id' => '22',
    'status_id' => '1',
    'changed_by_user_id' => '22',
    'note' => 'Order kurir dibuat oleh customer melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 20:15:46',
  ),
  139 => 
  array (
    'id' => '140',
    'order_id' => '22',
    'status_id' => '2',
    'changed_by_user_id' => '18',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 03","phone":"081100000003","user_id":18,"driver_id":3,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Supra X","vehicle_plate":"H 1003 AA"}}',
    'created_at' => '2026-07-15 20:16:08',
  ),
  140 => 
  array (
    'id' => '141',
    'order_id' => '22',
    'status_id' => '4',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"COURIER"}',
    'created_at' => '2026-07-15 20:19:26',
  ),
  141 => 
  array (
    'id' => '142',
    'order_id' => '22',
    'status_id' => '5',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"COURIER"}',
    'created_at' => '2026-07-15 20:20:56',
  ),
  142 => 
  array (
    'id' => '143',
    'order_id' => '22',
    'status_id' => '6',
    'changed_by_user_id' => '18',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"COURIER"}',
    'created_at' => '2026-07-15 20:20:59',
  ),
  143 => 
  array (
    'id' => '144',
    'order_id' => '22',
    'status_id' => '7',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"COURIER"}',
    'created_at' => '2026-07-15 20:24:50',
  ),
  144 => 
  array (
    'id' => '145',
    'order_id' => '22',
    'status_id' => '8',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"COURIER"}',
    'created_at' => '2026-07-15 20:25:05',
  ),
  145 => 
  array (
    'id' => '146',
    'order_id' => '22',
    'status_id' => '9',
    'changed_by_user_id' => '18',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"COURIER"}',
    'created_at' => '2026-07-15 20:25:50',
  ),
  146 => 
  array (
    'id' => '147',
    'order_id' => '23',
    'status_id' => '1',
    'changed_by_user_id' => '24',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-15 20:26:55',
  ),
  147 => 
  array (
    'id' => '148',
    'order_id' => '23',
    'status_id' => '2',
    'changed_by_user_id' => '25',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 05","phone":"081100000005","user_id":25,"driver_id":5,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Vario 150","vehicle_plate":"H 1005 AA"}}',
    'created_at' => '2026-07-15 20:27:29',
  ),
  148 => 
  array (
    'id' => '149',
    'order_id' => '23',
    'status_id' => '4',
    'changed_by_user_id' => '25',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:31:29',
  ),
  149 => 
  array (
    'id' => '150',
    'order_id' => '23',
    'status_id' => '6',
    'changed_by_user_id' => '25',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:32:14',
  ),
  150 => 
  array (
    'id' => '151',
    'order_id' => '23',
    'status_id' => '7',
    'changed_by_user_id' => '25',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:36:56',
  ),
  151 => 
  array (
    'id' => '152',
    'order_id' => '23',
    'status_id' => '8',
    'changed_by_user_id' => '25',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:37:11',
  ),
  152 => 
  array (
    'id' => '153',
    'order_id' => '23',
    'status_id' => '9',
    'changed_by_user_id' => '25',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-15 20:37:56',
  ),
  153 => 
  array (
    'id' => '154',
    'order_id' => '24',
    'status_id' => '1',
    'changed_by_user_id' => '8',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-16 20:41:02',
  ),
  154 => 
  array (
    'id' => '155',
    'order_id' => '24',
    'status_id' => '10',
    'changed_by_user_id' => '8',
    'note' => 'Pesanan tidak jadi',
    'metadata' => '{"cancellation_penalty": 0}',
    'created_at' => '2026-07-16 20:41:21',
  ),
  155 => 
  array (
    'id' => '156',
    'order_id' => '25',
    'status_id' => '1',
    'changed_by_user_id' => '8',
    'note' => 'Order Antar Jemput dibuat oleh customer.',
    'metadata' => NULL,
    'created_at' => '2026-07-16 19:41:49',
  ),
  156 => 
  array (
    'id' => '157',
    'order_id' => '25',
    'status_id' => '2',
    'changed_by_user_id' => '25',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 05","phone":"081100000005","user_id":25,"driver_id":5,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Vario 150","vehicle_plate":"H 1005 AA"}}',
    'created_at' => '2026-07-16 19:56:28',
  ),
  157 => 
  array (
    'id' => '158',
    'order_id' => '25',
    'status_id' => '4',
    'changed_by_user_id' => '25',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"RIDE"}',
    'created_at' => '2026-07-16 20:01:10',
  ),
  158 => 
  array (
    'id' => '159',
    'order_id' => '25',
    'status_id' => '6',
    'changed_by_user_id' => '25',
    'note' => 'Driver action BOARD_PASSENGER',
    'metadata' => '{"action_code":"BOARD_PASSENGER","service_type":"RIDE"}',
    'created_at' => '2026-07-16 20:01:55',
  ),
  159 => 
  array (
    'id' => '160',
    'order_id' => '25',
    'status_id' => '7',
    'changed_by_user_id' => '25',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"RIDE"}',
    'created_at' => '2026-07-16 20:30:52',
  ),
  160 => 
  array (
    'id' => '161',
    'order_id' => '25',
    'status_id' => '8',
    'changed_by_user_id' => '25',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"RIDE"}',
    'created_at' => '2026-07-16 20:31:07',
  ),
  161 => 
  array (
    'id' => '162',
    'order_id' => '25',
    'status_id' => '9',
    'changed_by_user_id' => '25',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"RIDE"}',
    'created_at' => '2026-07-16 20:31:52',
  ),
  162 => 
  array (
    'id' => '187',
    'order_id' => '30',
    'status_id' => '1',
    'changed_by_user_id' => '27',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-17 09:00:43',
  ),
  163 => 
  array (
    'id' => '188',
    'order_id' => '30',
    'status_id' => '2',
    'changed_by_user_id' => '18',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 03","phone":"081100000003","user_id":18,"driver_id":3,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Supra X","vehicle_plate":"H 1003 AA"}}',
    'created_at' => '2026-07-17 09:01:50',
  ),
  164 => 
  array (
    'id' => '189',
    'order_id' => '30',
    'status_id' => '3',
    'changed_by_user_id' => '18',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":67}',
    'created_at' => '2026-07-17 09:01:58',
  ),
  165 => 
  array (
    'id' => '190',
    'order_id' => '30',
    'status_id' => '5',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 09:17:45',
  ),
  166 => 
  array (
    'id' => '191',
    'order_id' => '30',
    'status_id' => '6',
    'changed_by_user_id' => '18',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 09:17:47',
  ),
  167 => 
  array (
    'id' => '192',
    'order_id' => '30',
    'status_id' => '7',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 09:36:47',
  ),
  168 => 
  array (
    'id' => '193',
    'order_id' => '30',
    'status_id' => '8',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 09:37:02',
  ),
  169 => 
  array (
    'id' => '194',
    'order_id' => '30',
    'status_id' => '9',
    'changed_by_user_id' => '18',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 09:37:47',
  ),
  170 => 
  array (
    'id' => '195',
    'order_id' => '31',
    'status_id' => '1',
    'changed_by_user_id' => '28',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-17 10:15:00',
  ),
  171 => 
  array (
    'id' => '196',
    'order_id' => '31',
    'status_id' => '2',
    'changed_by_user_id' => '25',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 05","phone":"081100000005","user_id":25,"driver_id":5,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Vario 150","vehicle_plate":"H 1005 AA"}}',
    'created_at' => '2026-07-17 10:16:40',
  ),
  172 => 
  array (
    'id' => '197',
    'order_id' => '31',
    'status_id' => '3',
    'changed_by_user_id' => '25',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":69}',
    'created_at' => '2026-07-17 10:18:04',
  ),
  173 => 
  array (
    'id' => '198',
    'order_id' => '31',
    'status_id' => '5',
    'changed_by_user_id' => '25',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 10:43:17',
  ),
  174 => 
  array (
    'id' => '199',
    'order_id' => '31',
    'status_id' => '6',
    'changed_by_user_id' => '25',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 10:43:19',
  ),
  175 => 
  array (
    'id' => '200',
    'order_id' => '31',
    'status_id' => '7',
    'changed_by_user_id' => '25',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 10:52:09',
  ),
  176 => 
  array (
    'id' => '201',
    'order_id' => '31',
    'status_id' => '8',
    'changed_by_user_id' => '25',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 11:01:39',
  ),
  177 => 
  array (
    'id' => '202',
    'order_id' => '31',
    'status_id' => '9',
    'changed_by_user_id' => '25',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 11:02:24',
  ),
  178 =>
  array (
    'id' => '203',
    'order_id' => '32',
    'status_id' => '1',
    'changed_by_user_id' => '29',
    'note' => 'Order Nitip dibuat melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-17 13:20:00',
  ),
  179 =>
  array (
    'id' => '204',
    'order_id' => '32',
    'status_id' => '2',
    'changed_by_user_id' => '18',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 03","phone":"081100000003","user_id":18,"driver_id":3,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Supra X","vehicle_plate":"H 1003 AA"}}',
    'created_at' => '2026-07-17 13:21:30',
  ),
  180 =>
  array (
    'id' => '205',
    'order_id' => '32',
    'status_id' => '3',
    'changed_by_user_id' => '18',
    'note' => 'Driver mulai memproses merchant Nitip.',
    'metadata' => '{"action_code":"MERCHANT_OPEN_CONFIRMED","pickup_location_id":71}',
    'created_at' => '2026-07-17 13:24:00',
  ),
  181 =>
  array (
    'id' => '206',
    'order_id' => '32',
    'status_id' => '5',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 13:40:00',
  ),
  182 =>
  array (
    'id' => '207',
    'order_id' => '32',
    'status_id' => '6',
    'changed_by_user_id' => '18',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 13:40:20',
  ),
  183 =>
  array (
    'id' => '208',
    'order_id' => '32',
    'status_id' => '7',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 13:55:00',
  ),
  184 =>
  array (
    'id' => '209',
    'order_id' => '32',
    'status_id' => '8',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 13:57:30',
  ),
  185 =>
  array (
    'id' => '210',
    'order_id' => '32',
    'status_id' => '9',
    'changed_by_user_id' => '18',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"SHOPPING"}',
    'created_at' => '2026-07-17 13:58:00',
  ),
  186 =>
  array (
    'id' => '211',
    'order_id' => '33',
    'status_id' => '1',
    'changed_by_user_id' => '30',
    'note' => 'Order kurir dibuat oleh customer melalui chatbot.',
    'metadata' => NULL,
    'created_at' => '2026-07-17 14:30:00',
  ),
  187 =>
  array (
    'id' => '212',
    'order_id' => '33',
    'status_id' => '2',
    'changed_by_user_id' => '18',
    'note' => 'Order diterima oleh driver.',
    'metadata' => '{"driver_snapshot":{"name":"Driver 03","phone":"081100000003","user_id":18,"driver_id":3,"vehicle_type":"Motor Matic","vehicle_brand":"Honda","vehicle_model":"Supra X","vehicle_plate":"H 1003 AA"}}',
    'created_at' => '2026-07-17 14:31:00',
  ),
  188 =>
  array (
    'id' => '213',
    'order_id' => '33',
    'status_id' => '4',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_PICKUP',
    'metadata' => '{"action_code":"ARRIVE_PICKUP","service_type":"COURIER"}',
    'created_at' => '2026-07-17 14:40:00',
  ),
  189 =>
  array (
    'id' => '214',
    'order_id' => '33',
    'status_id' => '5',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_PICKED_UP',
    'metadata' => '{"action_code":"CONFIRM_PICKED_UP","service_type":"COURIER"}',
    'created_at' => '2026-07-17 14:42:00',
  ),
  190 =>
  array (
    'id' => '215',
    'order_id' => '33',
    'status_id' => '6',
    'changed_by_user_id' => '18',
    'note' => 'Driver action START_DELIVERY',
    'metadata' => '{"action_code":"START_DELIVERY","service_type":"COURIER"}',
    'created_at' => '2026-07-17 14:42:20',
  ),
  191 =>
  array (
    'id' => '216',
    'order_id' => '33',
    'status_id' => '7',
    'changed_by_user_id' => '18',
    'note' => 'Driver action ARRIVE_DROPOFF',
    'metadata' => '{"action_code":"ARRIVE_DROPOFF","service_type":"COURIER"}',
    'created_at' => '2026-07-17 15:00:00',
  ),
  192 =>
  array (
    'id' => '217',
    'order_id' => '33',
    'status_id' => '8',
    'changed_by_user_id' => '18',
    'note' => 'Driver action CONFIRM_DELIVERED',
    'metadata' => '{"action_code":"CONFIRM_DELIVERED","service_type":"COURIER"}',
    'created_at' => '2026-07-17 15:01:30',
  ),
  193 =>
  array (
    'id' => '218',
    'order_id' => '33',
    'status_id' => '9',
    'changed_by_user_id' => '18',
    'note' => 'Driver action COMPLETE_ORDER',
    'metadata' => '{"action_code":"COMPLETE_ORDER","service_type":"COURIER"}',
    'created_at' => '2026-07-17 15:02:00',
  ),
);
