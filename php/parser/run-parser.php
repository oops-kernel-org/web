<?php
/* Created by Petr Oros
 * mail: poros@redhat.com
 * Date: 25.3.2013
 * Description: Load raw oops from db and parse it
 * parsed oops have this format:
 * $output["arch"] -> Architecture
 * $output["backtrace"] -> Full parsed backtrace
 * $output["adinfo"] -> additional oops specific info (for example pte, pmd)
 * $output["backtrace_modules"] -> Modules parcipied on oops
 * $output["bugline"] -> First line of oops ( cleaned and marked )
 * $output["class"] -> Oops class
 * $output["dissasm"] -> Dissassembled Code
 * $output["distro"] -> Distribution
 * $output["guiltylink"] -> Direct link into git ( guilty function )
 * $output["hwname1"] -> pc Hardware name
 * $output["hwname2"] -> pc second hardware name
 * $output["ip"] -> RIP or EIP ( array, contain function name, address...)
 * $output["lastsysfs"] -> Last used system file
 * $output["modules"] -> All modules inserted into kernel in oops time
 * $output["oopstype"] -> Oops type ( bug, warn, etc.)
 * $output["registers"] -> CPU registers and his content
 * $output["stack"] -> Stack content
 * $output["tainted"] -> Tained marks
 * $output["taintedstr"] -> "Translated" tainted chars
 * $output["version"] -> Kernel version
 * $output["guilty_file"] -> file with quilty function
 * $output["guilty_function"] -> quilty function
 * $output["guilty_module"] -> quilty module
 * $output["stamp"] -> timestamp
*/
if (!defined('WP_ADMIN')) define('WP_ADMIN', true);
require "../wp-load.php";
require "../wp-admin/includes/admin.php";
ini_set('memory_limit', '1024M');
echo "Kernel oops parser v1.3\n";
$inserted = 0;
$debug = False;
unset($insert_cache);
$insert_cache = Array();
$no_older_than = new DateTime('2999-12-31');
$stats["fail"] = $stats["insert"] = $stats["dup"] = 0;
$oops_query = mysql_query("SELECT * FROM raw_data WHERE status=" . P_NEW . " LIMIT " . (int)$oopscfg["limit"]);
if (!$oops_query) die(mysql_error());
if (!($count = mysql_num_rows($oops_query))) die("No new data");
else echo "Ready to parse: " . $count . "\n";
// Init kernel list cache
InitCache("kernel");
// Loop over all new reported oopses
while ($oops = mysql_fetch_array($oops_query)) {
    unset($output);
    $current = $oops["raw"];
    $version = CodeVersionData($current);
    if (!$version) {
        if ($debug) {
            echo "Fail(" . $oops["id"] . ") -> not a oops, ignoring\n";
            print_r($current);
            echo "\n";
        }
        $db_cache["rawlist"][$oops["id"]] = P_FAIL;
        $stats["fail"]++;
        continue;
    }
    $current = InitialCleanupFunc($current);
    preg_match_all("/([^\n]*)\n/s", $current, $out);
    $proc_backtrace = 0;
    $output["version"] = $version;
    if (!isset($db_cache["kernel"][$version])) {
        if ($debug) {
            echo "Kernel: " . $version . " not found in DB -> FAIL\n";
            print_r($current);
            echo "\n";
        }
        $db_cache["rawlist"][$oops["id"]] = P_FAIL;
        $stats["fail"]++;
        continue;
    }
    $output["oopstype"] = "unknown";
    $output["backtrace"] = "";
    $output["backtrace_modules"] = "";
    $output["distro"] = GuessDistro($current);
    $output["tainted"] = FindTainted($current);
    $output["arch"] = FindArch($current);
    $t = explode(" ", $oops["timestamp"]);
    $output["stamp"] = $t[0];
    if (new DateTime($output["stamp"]) < $no_older_than) $no_older_than = new DateTime($output["stamp"]);
    $output["rawid"] = $oops["id"];
    $output["dissasm"] = RawDecode($current);
    $output["hwname1"] = FindBIOS($current);
    $output["hwname2"] = FindHW($current);
    $output["registers"] = FindRegisters($current);
    $output["stack"] = FindStack($current);
    $output["ip"] = FindXIP($current);
    $output["lastsysfs"] = FindLastSysFS($current);
    unset($backtrace);
    foreach ($out[1] as $key => $line) {
        if ($proc_backtrace > 0) {
            # normal call trace
            if (preg_match("/.*\>\] (.*)(\+0x.*)/", $line, $matches)) {
                $backtrace[] = $matches[1] . $matches[2];
                if (preg_match("/.*\>\] (.*)\+0x[0-9a-f]+\/0x[0-9a-f]+ \[(.*)\]/", $line, $matches)) {
                    PushModule($matches[2], $output["backtrace_modules"]);
                }
            # PPC back trace    
            } else if (preg_match("/\[[a-f0-9]+\] \[[a-f0-9]+\] \.(.*)(\+0x.*)/", $line, $matches) ||
                       preg_match("/\[[a-f0-9]+\] \[[a-f0-9]+\] (.*)(\+0x.*)/", $line, $matches) ||
                       preg_match("/\[\<[0-9a-fA-F]{8,16}\>\] [\? ]{0,2}(0x[0-9a-fA-F]{8,16})/", $line, $matches)) {
                $backtrace[] = $matches[1] . $matches[2];
            # ksymoops garbled calltrace
            }
            else if (preg_match("/.*\[.*\+.*\/.*\] (.*)(\+0x.*)/", $line, $matches)) {
                $backtrace[] = $matches[1] . $matches[2];
                if (preg_match("/.*\[.*\+.*\/.*\] (.*)\+0x[0-9a-f]+\/0x[0-9a-f]+ \[(.*)\]/", $line, $matches)) {
                    PushModule($matches[2], $output["backtrace_modules"]);
                }
            } else $proc_backtrace = False;
            continue;
        } else $proc_backtrace = False;
        if (preg_match("/^Modules linked in: (.*)/", $line, $matches)) {
            if (($pos = strpos($matches[1], "[last unloaded")) !== False) $matches[1] = substr($matches[1], 0, $pos - 1);
            foreach (explode(" ", $matches[1]) as $c) PushModule($c, $output["modules"]);
        }
        if (preg_match("/^Hardware name:/", $line, $matches)) {
            continue;
        }
        if (preg_match("/Call Trace:/", $line) || preg_match("/Backtrace:/", $line)) {
            $proc_backtrace = 1;
        }
        if (preg_match("/\[\<.*\>\] (.*)\+0x/", $line, $matches)) {
            $backtrace[$proc_backtrace] = $matches[1];
            $proc_backtrace = 1;
            if (preg_match("/.*\>\] (.*)\+0x[0-9a-f]+\/0x[0-9a-f]+ \[(.*)\]/", $line, $matches)) {
                PushModule($matches[2], $output["backtrace_modules"]);
            }
        }
        if (!isset($output["oopstype"]) || $output["oopstype"] == "unknown") {
            if (preg_match("/NETDEV WATCHDOG: (.*)/", $line, $match)) {
                $output["bugline"] = "NETDEV WATCHDOG: " . $match[1];
                $output["oopstype"] = "watchdog";
                $output["class"] = "warn";
            }
            if (preg_match("/^BUG: Bad page state in process/", $line)) {
                if (($pos = strpos($line, "pfn:")) !== False) {
                    $output["bugline"] = trim(substr($line, 0, $pos));
                    $output["adinfo"][] = trim(substr($line, $pos));
                } else $output["bugline"] = $line;
                $output["oopstype"] = "bad page state";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: unable to handle kernel paging request/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "kernel page fault";
                $output["class"] = "bug";
            }
            if (preg_match("/request at ([0-9a-fA-F]+)/", $line, $match)) {
                $output["bugline"] = "BUG: unable to handle kernel paging request at " . $match[1];
                $output["oopstype"] = "kernel page fault";
                $output["class"] = "bug";
            }
            if (preg_match("/^do_IRQ: stack overfow:/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "stack overflow";
                $output["class"] = "warn";
            }
            if (preg_match("/near stack overflow \(cur:/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "stack overflow";
                $output["class"] = "warn";
            }
            if (preg_match("/^BUG: Bad page map in process.*/", $line)) {
                if (strpos($line, "pte:") !== False || strpos($line, "pmd:") !== False) {
                    unset($match);
                    if (preg_match("/pte[: ]+([0-9a-fA-F]+)/", $line, $match)) {
                        $output["adinfo"][] = trim("pte: " . $match[1]);
                        $line = preg_replace("/pte[: ]+[0-9a-fA-F]+/", "", $line);
                    }
                    unset($match);
                    if (preg_match("/pmd[: ]+([0-9a-fA-F]+)/", $line, $match)) {
                        $output["adinfo"][] = trim("pmd: " . $match[1]);
                        $line = preg_replace("/pmd[: ]+[0-9a-fA-F]+/", "", $line);
                    }
                }
                $output["bugline"] = $line;
                $output["oopstype"] = "bad page map";
                $output["class"] = "bug";
            }
            if (preg_match("/general protection fault: [0-9]+/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "kernel page fault";
                $output["class"] = "oops";
            }
            if (preg_match("/^BUG: soft lockup /", $line)) {
                unset($matches);
                if (!preg_match("/([0-9]+)s! \[(.+):[0-9]+\]/", $line, $matches)) {
                    echo "ERROR: " . $line . "\n";
                    $output["bugline"] = $line;
                } else {
                    $output["bugline"] = "BUG: soft lockup in " . $matches[2];
                    if ($matches[1] <= 60) $output["bugline"].= " less than 60s";
                    if ($matches[1] > 60) $output["bugline"].= " more than 60s!";
                }
                $output["oopstype"] = "soft lockup";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: trying to register non-static key!/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "lockdep bug";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: spinlock wrong CPU on/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "wrong spinlock";
                $output["class"] = "bug";
            }
            if (preg_match("/^kernel BUG at (.*)/", $line, $matches)) {
                $output["oopstype"] = "BUG statement";
                $output["bugline"] = "Kernel BUG statement at " . $matches[1];
                $output["class"] = "bug";
            }
            if (preg_match("/^RTNL: assertion failed at (.*)/", $line, $matches)) {
                $output["oopstype"] = "Networking assertion";
                $output["bugline"] = "RTNL: assertion failed at " . $matches[1];
                $output["class"] = "bug";
            }
            if (preg_match("/^ALSA (.*): BUG?/", $line, $matches)) {
                $output["oopstype"] = "ALSA BUG";
                $output["bugline"] = "ALSA " . $matches[1];
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: warning at (.*)/", $line, $matches)) {
                $output["oopstype"] = "WARN_ON statement";
                $output["bugline"] = "BUG: warning at " . $matches[1];
                $output["class"] = "warn";
            }
            if (preg_match("/^(IRQ handler type mismatch for IRQ .*)/", $line, $matches)) {
                $output["oopstype"] = "IRQ handler mismatch";
                $output["bugline"] = $matches[1];
                $output["class"] = "warn";
            }
            if (preg_match("/^(irq [0-9]+: nobody cared).*/", $line, $matches)) {
                $output["oopstype"] = "IRQ nobody cared";
                $output["bugline"] = $matches[1];
                $output["class"] = "warn";
            }
            if (preg_match("/^Badness at (.*)/", $line, $matches)) {
                $output["oopstype"] = "WARN_ON statement";
                $output["bugline"] = "Badness at " . $matches[1];
                $output["class"] = "warn";
            }
            if (preg_match("/^(imklog [0-9]+\.[0-9]+\.[0-9]+.*)/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "Imklog trace message";
                $output["class"] = "imklog";
            }
            if (preg_match("/WARNING: at (.*)/", $line, $matches)) {
                // skip watchdog, and set WATCHDOG bugline
                if (preg_match("/NETDEV WATCHDOG:/", $current)) continue;
                $output["oopstype"] = "WARN_ON statement";
                $output["bugline"] = "WARNING: at " . $matches[1];
                $output["class"] = "warn";
            }
            if (preg_match("/WARNING: CPU: [0-9]+ PID: [0-9]+ at (.*)/", $line, $matches)) {
                $output["oopstype"] = "WARN_ON statement";
                $output["bugline"] = "WARNING: at " . $matches[1];
                $output["class"] = "warn";
            }
            if (preg_match("/^BUG: spinlock lockup on CPU/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "spinlock lockup";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: write-lock lockup on CPU/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "write-lock lockup";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: atomic counter underflow at/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "atomic underflow";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: spinlock bad magic on CPU/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "spinlock bad magic";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: rwlock bad magic on/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "rwlock bad magic";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: [Ss]pinlock recursion on CPU/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "spinlock recursion";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: NMI Watchdog detected LOCKUP on/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "livelock";
                $output["class"] = "bug";
            }
            if (preg_match("/^Eeek! page_mapcount(page) went negative/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "VM error";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: spinlock already unlocked on CPU/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "extra spinunlock";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: scheduling while atomic/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "atomic schedule";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: scheduling with irqs disabled:/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "atomic schedule";
                $output["class"] = "bug";
            }
            if (preg_match("/^BUG: spinlock cpu recursion on:/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "spinlock recursion";
                $output["class"] = "bug";
            }
            if (preg_match("/^sysctl table check failed:/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "sysctl check";
                $output["class"] = "warn";
            }
            if (preg_match("/^BUG: using smp_processor_id\(\) in preemptible/", $line)) {
                $line = preg_replace("/\[.*\]/", "", $line);
                $line = preg_replace("/\\/.*$/", "", $line);
                $line = str_replace("  ", " ", $line);
                $output["bugline"] = $line;
                $output["oopstype"] = "smp_processor_id";
                $output["class"] = "warn";
            }
            if (preg_match("/^divide error: /", $line)) {
                $output["bugline"] = "BUG: " . $line;
                $output["oopstype"] = "divide error";
                $output["class"] = "warn";
            }
            if (preg_match("/^BUG: sleeping function called from invalid context/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "invalid context";
                $output["class"] = "warn";
            }
            if (preg_match("/^double fault:/", $line)) {
                $output["bugline"] = $line;
                $output["oopstype"] = "double fault";
                $output["class"] = "oops";
            }
            if (preg_match("/ference at (.*)/", $line, $match2)) {
                if (preg_match("/Bad [ER]IP value/", $current)) $output["bugline"] = "BUG: Unable to handle kernel NULL pointer dereference with Bad IP Value";
                elseif (preg_match("/[ER]IP is at (0x[0-9a-f]+)/", $current, $match)) $output["bugline"] = "BUG: Unable to handle kernel NULL pointer dereference at " . trim($match[1]);
                elseif (preg_match("/[ER]IP is at ([^\+]+)[\+]/", $current, $match)) $output["bugline"] = "BUG: Unable to handle kernel NULL pointer dereference at " . trim($match[1]);
                elseif (preg_match("/IP: \[.*\] ([^\+]+)\+/", $current, $match)) $output["bugline"] = "BUG: Unable to handle kernel NULL pointer dereference at " . trim($match[1]);
                else $output["bugline"] = "BUG: Unable to handle kernel NULL pointer dereference at " . trim($match2[1]);
                $output["oopstype"] = "kernel NULL pointer";
                $output["class"] = "bug";
            }
        }
    }
    if (!isset($output["bugline"])) {
        if ($debug) {
            echo "Fail(" . $oops["id"] . ") -> invalid bugline, ignoring\n";
            print_r($current);
            echo "\n";
        }
        $db_cache["rawlist"][$oops["id"]] = P_FAIL;
        $stats["fail"]++;
        continue;
    }
    $output["bugline"] = Anonymize_bugline($output["bugline"]);
    FixBacktrace($current, $output["oopstype"], $output["tainted"], $temp);
    $output["guilty"] = FindGuilty($output, $current);
    $output["guiltylink"] = MarkUpBugline($output["bugline"], $output["version"], $output["arch"]);
    if (isset($output["backtrace_modules"]) && $output["backtrace_modules"] != "") $output["backtrace_modules"] = explode(" ", $output["backtrace_modules"]);
    if (isset($output["modules"]) && $output["modules"] != "") $output["modules"] = explode(" ", $output["modules"]);
    if (isset($backtrace)) {
        foreach ($backtrace as $key => $value) {
            $output["ugly_backtrace"][] = $value;
            if (($temp = CleanUpFunction($value, $output["oopstype"])) != "") $output["cleaned_backtrace"][] = $temp;
        }
        unset($output["ugly_backtrace"]);
    }
    if (isset($output['stack'])) $output['stack'] = AddHtmlTags($output['stack'], "array");
    if (isset($output['registers'])) $output['registers'] = AddHtmlTags($output['registers'], "array", True);
    if (isset($output['disassm'])) $output['disassm'] = AddHtmlTags($output['disassm'], "string");
    // bugline improves for better usability
    if ($output["oopstype"] == "kernel page fault") {
        if (preg_match("/general protection fault: [0-9]+/", $output["bugline"]) && isset($output["cleaned_backtrace"][0])) $output["bugline"] = "general protection fault in " . $output["cleaned_backtrace"][0];
        if (preg_match("/request at/", $output["bugline"]) && isset($output["cleaned_backtrace"][0])) $output["bugline"] = "kernel paging request at " . $output["cleaned_backtrace"][0];
    }
    // try found caused function in backtrace
    if ((!isset($output["guilty"]["function"]) || $output["guilty"]["function"] == "") && isset($output["cleaned_backtrace"][0])) $output["guilty"]["function"] = $output["cleaned_backtrace"][0];
    if ($output["oopstype"] == "atomic schedule") {
        if (preg_match("/^BUG: scheduling while atomic/", $output["bugline"]) && isset($output["guilty"]["function"]) && $output["guilty"]["function"] != "") $output["bugline"] = "BUG: scheduling while atomic in " . $output["guilty"]["function"];
    }
    if (isset($output["adinfo"])) {
        $output["cleaned_backtrace"][] = "<b>Additional info:</b>";
        foreach ($output["adinfo"] as $k => $v) $output["cleaned_backtrace"][] = $v;
    }
    $insert_cache[$inserted] = $output;
    $inserted++;
}
DoInsert($insert_cache);
if (is_array($stats)) {
    echo "Summary: \n";
    echo "\tNew inserted: " . $stats["insert"] . "\n";
    echo "\tDuplicity: " . $stats["dup"] . "\n";
    echo "\tFailed: " . $stats["fail"] . "\n";
}
?>
