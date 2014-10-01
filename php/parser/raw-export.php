<?php
require "../wp-load.php";
ini_set('memory_limit', '1024M');
global $wpdb;
$limits["start"] = 0;
$limits["end"] = 20000;
$limits["step"] = $limits["end"];
$cont = True;

foreach (glob($oopscfg["safeout"]["dir"] . "*.part.last") as $filename) {
    $prelast = explode('-',str_replace($oopscfg["safeout"]["dir"],"",$filename));
    foreach (glob($oopscfg["safeout"]["dir"] . "*" . $prelast[0] . ".part") as $filename2) {
        echo "Unlinking $filename2\n";
        unlink($filename2);
    }
    echo "Unlinking $filename\n";
    unlink($filename);
}

while($cont){
    $fname = $limits["start"] . "-" . $limits["end"] . ".part";
    if(!is_file($oopscfg["safeout"]["dir"] . $fname)) {
        $sql = "SELECT id, raw, timestamp FROM raw_data WHERE status=1 OR status=0 ORDER BY id ASC LIMIT " . $limits["start"] . ", " . $limits["step"];
        $results = $wpdb->get_results($sql);
        $out = "";
        $count = 0;
        foreach ($results as $row) {
            $out.= '<dump>' . "\n";
            $out.= ' <id>' . $row->id . '</id>' . "\n";
            $out.= ' <stamp>' . $row->timestamp . '</stamp>' . "\n";
            $out.= ' <raw>' . htmlspecialchars(ObfuscateRaw($row->raw)) . '</raw>' . "\n";
            $out.= '</dump>' . "\n";
            $count++;
        }
        if ($out != "") {
            if ($count < $limits["step"]){
                $cont = false;
                $handle = fopen($oopscfg["safeout"]["dir"] . $fname . ".last", "w");
            }
            else
                $handle = fopen($oopscfg["safeout"]["dir"] . $fname, "w");
            if ($limits["start"] == 0) {
                fwrite($handle, '<?xml version="1.0"?>' . "\n");
                fwrite($handle, '<rawlist>' . "\n");
            }
            fwrite($handle, $out);
            if ($count < $limits["step"]){
                fwrite($handle, '</rawlist>' . "\n");
                print $fname . ".last...done\n";
            } else
                print $fname . "...done\n";
            fclose($handle);
        }
    } else {
        echo $fname . "...skiping\n";
    }
    $limits["start"] = $limits["end"];
    $limits["end"] += $limits["step"];
}
?>
