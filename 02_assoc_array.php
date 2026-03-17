<?php
//set up the associative array
$height['Peter'] = 1.6;
$height['Paul'] = 1.5;
$height['Mary'] = 1.3;

//choose default for whose height we will print
$whoseheight="Tom";

//is that value set ?
if(isset( $height[$whoseheight])) {
	
	//yes print it out
	echo $whoseheight." height is ".$height[$whoseheight];

} else {
	//no say we don't have the height
      	echo "I don't have a height for ".$whoseheight;
}
?>
