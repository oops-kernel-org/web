<?php
// load all files in directory, parse it, and import into DB
require "../wp-load.php";
ini_set('memory_limit', '1024M');

$base_data = Array();
foreach (array("kernel", "file", "function") as $current) {
    echo "Loading " . $current . " list....";
    $item = $current == "kernel" ? "version" : "name";
    $query = mysql_query("SELECT * FROM " . $current . " ORDER BY " . $item . " ASC");
    if ($query) {
        while ($row = mysql_fetch_array($query)) $base_data[$current][$row[$item]] = $row["id"];
        echo "OK\n";
    } else die("EMPTY Table\n");
    unset($query);
}
if (is_array($base_data["kernel"])) uksort($base_data["kernel"], "kcmp");

// get file list
$dirContent = array();
if ($dirhandle = opendir($oopscfg["tagdir"])) {
    while (false !== ($entry = readdir($dirhandle))) {
        if ($entry != "." && $entry != ".." && $entry != "tag_import.php" && $entry != "latest") $dirContent[] = $entry;
    }
    closedir($dirhandle);
}
if (is_array($dirContent)) usort($dirContent, "kcmp");

$new_data = Array();
foreach ($dirContent as $kernel) {
    if (!isset($base_data["kernel"][$kernel])) $new_data["kernel"][$kernel] = True;
    if (!filesize($oopscfg["tagdir"] . $kernel)) continue;
    echo "Searching data in " . $kernel . "......";
    $handle = fopen($oopscfg["tagdir"] . $kernel, "r");
    if ($handle) {
        unset($buffer, $data);
        while (($buffer = fgets($handle, 4096)) !== false) {
            $buffer = str_replace("./", "", $buffer);
            $buffer = str_replace("\n", "", $buffer);
            $data = explode(":", strtolower($buffer));
            # data [0] -> file
            # data [1] -> line
            # data [2] -> function
            if (!isset($base_data["file"][$data[0]])) $new_data["file"][$data[0]] = True;
            if (!isset($base_data["function"][$data[2]])) $new_data["function"][$data[2]] = True;
            unset($buffer, $data);
        }
        if (!feof($handle)) {
            echo "Error: unexpected fgets() fail\n";
        } else fclose($handle);
        echo "OK\n";
    }
}

$sqli = "";
foreach (array("kernel", "file", "function") as $item) {
    if (is_array($new_data[$item])) {
        echo "Inserting $item....";
        $item_name = $item == "kernel" ? "version" : "name";
        $index = 0;
        foreach ($new_data[$item] as $key => $value) {
            if ($sqli == "") {
                $sqli = "INSERT INTO $item ($item_name) VALUES ('$key')";
                $index++;
                continue;
            }
            $sqli.= ",('$key')";
            if ($index >= 1000) {
                if (!mysql_query($sqli)) {
                    echo $sqli . "\n";
                    echo mysql_error();
                }
                $sqli = "";
                $index = 0;
            } else $index++;
        }
        if ($sqli != "") {
            if (!mysql_query($sqli)) {
                echo $sqli . "\n";
                echo mysql_error();
            }
        }
        echo "OK\n";
    }
    unset($key, $value, $sqli);
    $sqli = "";
}
unset($key, $value, $sqli, $new_data, $base_data);
foreach (array("kernel", "file", "function") as $current) {
    echo "Reloading " . $current . " list....";
    $item = $current == "kernel" ? "version" : "name";
    $rev = $current == "kernel";
    $query = mysql_query("SELECT * FROM " . $current . " ORDER BY " . $item . " ASC");
    if ($query) {
        while ($row = mysql_fetch_array($query)) $base_data[$current][$row[$item]] = $row["id"];
        echo "OK\n";
    } else echo "EMPTY Table\n";
    unset($query);
}
if (is_array($base_data["kernel"])) uksort($base_data["kernel"], "kcmp");

// necessary for right incremental storage
$index = 0;
foreach ($base_data["kernel"] as $k => $v) {
    $base_data["order"][$v] = $i++;
}

// get last fileID
$query = mysql_query("SELECT id FROM file ORDER BY id DESC limit 1");
if (!$query) die("Can't load last file ID\n");
$temp = mysql_fetch_array($query);
$lastFile = $temp["id"];
$index = 0;
$query = "";

// loop over filelist
foreach ($dirContent as $kernel) {
    if (!filesize($oopscfg["tagdir"] . $kernel)) continue;
    echo "Processing " . $kernel . "....";
    $looplimit = 100000;
    $kernelID = $base_data["kernel"][$kernel];
    $handle = fopen($oopscfg["tagdir"] . $kernel, "r");
    if ($handle) {
        // loop over block
        while ($looplimit > 0) {
            // loop over file line ( break on limit )
            for ($ctrl = $looplimit; $ctrl > 0 && $looplimit > 0; $ctrl--) {
                if (($buffer = fgets($handle, 4096)) === false) {
                    $looplimit = -1;
                    fclose($handle);
                }
                $buffer = str_replace("./", "", $buffer);
                $buffer = str_replace("\n", "", $buffer);
                $cdata = explode(":", strtolower($buffer));
                $dsource[$base_data["file"][$cdata[0]]][] = $cdata;
                # data [0] -> file
                # data [1] -> line
                # data [2] -> function
                unset($buffer, $cdata);
            }
            $index = 0;
            $startfrom = 0;
            $append = 200;
            unset($value, $query, $kffindex);
    
            while (True) {
                $query = mysql_query("SELECT * FROM kffindex WHERE fileID >= " . $startfrom . " AND fileID <= " . ($startfrom + $append));
                if (!$query || ($startfrom > $lastFile)) break;
                while ($row = mysql_fetch_array($query)) {
                    $kffindex[$row["fileID"]][$row["functionID"]][$row["kernelID"]] = $row["line"];
                    unset($row);
                }
                for ($x = $startfrom;$x < ($startfrom + $append);$x++) {
                    if (is_array($dsource[$x])) {
                        foreach ($dsource[$x] as $key => $data) {
                            $insert = False;
                            if (!is_array($kffindex[$base_data["file"][$data[0]]][$base_data["function"][$data[2]]])) $insert = True;
                            else {
                                $last = PHP_INT_MAX;
                                foreach ($kffindex[$base_data["file"][$data[0]]][$base_data["function"][$data[2]]] as $k => $v) {
                                    if ($base_data["order"][$k] <= $base_data["order"][$kernelID]) $last = $k;
                                }
                                if ($kffindex[$base_data["file"][$data[0]]][$base_data["function"][$data[2]]][$last] != $data[1]) {
                                    $kffindex[$base_data["file"][$data[0]]][$base_data["function"][$data[2]]][$last] = $data[1];
                                    $insert = True;
                                }
                            }
                            if ($insert) {
                                if ($sql == "") {
                                    $sql = "INSERT INTO kffindex (kernelID, fileID, functionID, line) VALUES ($kernelID," . $base_data["file"][$data[0]] . "," . $base_data["function"][$data[2]] . "," . $data[1] . ")";
                                    $index = 0;
                                } else {
                                    $sql.= ",($kernelID," . $base_data["file"][$data[0]] . "," . $base_data["function"][$data[2]] . "," . $data[1] . ")";
                                }
                                if ($index >= 500) {
                                    if (!mysql_query($sql)) {
                                        echo $sql . "\n";
                                        echo mysql_error();
                                    }
                                    unset($sql);
                                    $sql = "";
                                    $index = 0;
                                } else $index++;
                            }
                            unset($buffer, $data);
                        }
                        unset($dsource[$x]);
                    }
                }
                if ($sql != "") {
                    if (!mysql_query($sql)) {
                        echo $sql . "\n";
                        echo mysql_error();
                    }
                    unset($query);
                }
                unset($key, $value, $sql, $dsource[$x]);
                $startfrom+= $append;
                unset($kffindex);
            }
        }
        $handle = fopen($oopscfg["tagdir"] . $kernel, "w");
        ftruncate($handle, 0);
        fclose($handle);
        echo "..OK\n";
        unset($dsource, $kffindex);
    }
}
?>
