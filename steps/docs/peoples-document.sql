standart top >>>>:

$id_section=2;
$title="title";
require("/usr/local/www/aphorism.ru/req/utils_v2.class");
$my=new class_utils;
require("ssi/registration_v2.php");
$Iid=(int)$_COOKIE[dartstudio]['Iid'];
if ($Iid < 1) { 	header("Location: logout.php"); };
$ip = $my -> getip();
$my->sql_connect();
include_once("ssi/top.shtml");

standart top end <<<<<<

IKodPersons - KodPersons or Persons_id - id of the person from `persons` table
seekName - seearching for Last Name in the forms
seekNum - search by number (int)
seekHis - search by history number (int)
seekNameEngl  - search by the English name
Ikod - id user online (int)
ip - ip user
seenform - form submitted (int) 1-yes,0-no
errorLevel (was Kod_analog)=1; -- error level
errorDesc (was Kod_error) -- error description
 


