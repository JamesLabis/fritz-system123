<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/../includes/functions.php';require_admin();
$type=$_GET['type']??'sales';
if($type==='inventory'){
 $rows=$pdo->query('SELECT sku,product_name,stock_quantity,low_stock_threshold,unit,price FROM products ORDER BY product_name')->fetchAll();
 $filename='buildsmart-inventory-'.date('Ymd').'.csv';
 $headers=['SKU','Product','Stock','Low stock threshold','Unit','Unit price'];
}else{
 $rows=$pdo->query("SELECT o.order_id,o.order_date,u.full_name,o.total_amount,o.delivery_fee,o.order_status,p.payment_method,p.payment_status FROM orders o JOIN users u ON u.user_id=o.user_id LEFT JOIN payments p ON p.order_id=o.order_id WHERE o.order_status<>'Cancelled' ORDER BY o.order_date DESC")->fetchAll();
 $filename='buildsmart-sales-'.date('Ymd').'.csv';
 $headers=['Order ID','Date','Customer','Total','Delivery fee','Status','Payment method','Payment status'];
}
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');
function csv_safe($v){return (is_string($v)&&$v!==''&&!is_numeric($v)&&strpbrk($v[0],"=+-@\t\r")!==false)?"'".$v:$v;}
log_activity($pdo,'export','report',null,$type.' CSV exported');
$out=fopen('php://output','w');fputcsv($out,$headers);foreach($rows as $r)fputcsv($out,array_map('csv_safe',array_values($r)));fclose($out);exit;
