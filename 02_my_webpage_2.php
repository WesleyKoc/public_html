<?php

echo <<<_HEAD
<html>

<head>

<title>02_my_form_1.php</title>

</head>

<body>
_HEAD;

if(isset($_POST['yname'])) {
echo "<p>You typed ",$_POST['yname']," into the box</p>";
  echo 'This is the <a href="02_my_form_1.php">link</a> back to the original (same) page';
} else {
  echo <<<_FORM

<form action="02_my_form_1.php" method="post">

<pre><font face="arial">

       Please enter your name:
       <input type="text" value="Zaphod Beeblebrox" name="yname"/>
       <input type="submit" value="Click me" />
</pre>

</form>

_FORM;
}

echo <<<_TAIL
</body>
</html>
_TAIL;

?>
