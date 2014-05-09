<?php
#UTILITIES
static $db_cache;
static $insert_cache;
static $kffcache;
static $no_older_than;
static $types = Array("function", "file", "driver", "module");
static $debug = False;
// tainted record in db cache
define("T_CLEAN", 0);
define("T_UPDATE", 1);
define("T_INSERT", 2);
// raw data status in db
define("P_NEW", 0);
define("P_OK", 1);
define("P_FAIL", 2);
// module type
define("MOD_INSERTED", 0);
define("MOD_BACKTRACE", 1);
/* arg1: raw oops
 * found and assemble Code: tag
*/
function RawDecode($originalRaw) {
    global $oopscfg;
    // cleanup
    $raw = str_replace("\n", " ", $originalRaw);
    $raw = str_replace("  ", " ", $raw);
    $raw = str_replace("Instruction dump:", "Code:", $raw);
    // match code
    if (preg_match("/Code: [0-9a-fA-F <>]*/", $raw, $match))
        $raw = $match[0];
    // setup flags and decodecode params
    $AFLAGS = "";
    if (preg_match("/EIP:/", $originalRaw)) $AFLAGS = "-32";
    if (preg_match("/RIP:/", $originalRaw)) $AFLAGS = "-64";
    if (preg_match("/NIP:/", $originalRaw)) {
        $ENV["CROSS_COMPILE"] = $oopscfg["binutils"] . "powerpc64-linux-gnu-";
        $ENV["ARCH"] = "powerpc64";
        if (preg_match("/NIP: [0-9a-fA-F]{16}[ ]/", $originalRaw))
            $AFLAGS = "a64";
        else $AFLAGS = "a32";
    }
    $process = proc_open("echo \"" . $raw . "\" | AFLAGS=-" . $AFLAGS . " " . $oopscfg["codedecode"], array(1 => array("pipe", "w")), $pipes, null, $ENV);
    if(is_resource($process)) {
        $output = stream_get_contents($pipes[1], -1);
        fclose($pipes[1]);
        proc_close($process);
        return $output;
    } return "";
}
/*
 * arg0: string, function header
 * return: string, function header cleaned from spaces, etc.
*/
function InitialCleanupFunc($func) {
    $func = preg_replace("/[-]*.*cut here.*[-]*\n/", "", $func);
    $func = preg_replace("/[-]*.*end trace.*[-]*\n/", "", $func);
    $func = preg_replace("/\[[ ]*[0-9]*\.[0-9]*[ ]*\][ ]*/", "", $func);
    $func = preg_replace("{[ ]+}", " ", $func);
    return $func;
}
function CleanUpModule($mod) {
    if ((strpos($mod, "0000") !== False) || preg_match("/^[0-9]+$/", $mod) || (strpos($mod, ":") !== False)) return "";
    $find = Array(']', '[', '.', '\\', '/', '+', '()', '(-)');
    $replace[] = '';
    $mod = str_replace($find, $replace, $mod);
    return trim($mod);
}
/* push module into list
 * arg1: new module
 * arg2: module list
*/
function PushModule($mod, &$modules) {
    $mod = CleanUpModule($mod);
    if ($mod == "" || $mod[0] == "<") return 0;
    if (!preg_match("/" . $mod . "/", $modules)) {
        if ($modules == "") $modules = $mod;
        else $modules = $modules . " $mod";
    }
    return 0;
}
/*
 * arg0: string, oops
 * return: string with replaced some gcc stuff
 * */
function FixUp($func) {
    if (preg_match("/\? \:.*\:(.*)/", $func, $matches)) $func = "? " . $matches[1];
    else if (preg_match("/\:.*\:(.*)/", $func, $matches)) $func = $matches[1];
    # strip of .clone.<number> gcc 4.4 stuff
    if (preg_match("/(.*)\.clone\.[0-9]+/", $func, $matches)) $func = $matches[1];
    $func = Demangle($func);
    return $func;
}
/*
 * arg0: raw oops
 * return: kernel version
*/
function CodeVersionData($raw) {
    $number = 0;
    #print "Coding based on $ver \n";
    if (preg_match("/([1-9]\.[0-9]+\.[0-9]+([.][0-9]+)?)/", $raw, $matches)) {
        $number = $matches[1];
        if (preg_match("/[-.]rc([0-9]+)/", $raw, $matches)) $number.= "-rc" . $matches[1];
    }
    if (preg_match("/([1-9]\.[0-9]+\.[0-9]+)-rc([0-9]+)/", $raw, $matches)) $number = $matches[1] . "-rc" . $matches[2];
    # fedora numbering
    if (preg_match("/([1-9]\.[0-9]+\.[0-9]+)\-0\.[0-9]+\.rc([0-9]+).*fc.*/", $raw, $matches)) $number = $matches[1] . "-rc";
    if (preg_match("/([1-9]\.[0-9]+\.[0-9]+)\-0\.[0-9]+\.rc([0-9])+\.git([0-9]+).*fc.*/", $raw, $matches)) $number = $matches[1] . "-rc";
    # mandriva numbering
    if (preg_match("/([1-9]\.[0-9]+\.[0-9]+)\-.*\-[0-9]+\.rc([0-9]+)\.[0-9]+mdv/", $raw, $matches)) $number = $matches[1] . "-rc";
    if (preg_match("/([1-9]\.[0-9]+\.[0-9]+)\-[0-9]+\.rc([0-9]+)\.[0-9]+mdv/", $raw, $matches)) $number = $matches[1] . "-rc";
    if (preg_match("/[ ]([3]\.[0-9]+)[-]([0-9]+)/", $raw, $matches)) {
        $number = $matches[1] . "." . $matches[2];
    }
    $exploded = explode(".", $number);
    if ($exploded[0] != 2 && $exploded[0] != 3) return False;
    if (isset($exploded[2]) && $exploded[2] == "0") return $exploded[0] . "." . $exploded[1];
    if (isset($exploded[2]) && strpos($exploded[2], "rc") !== False) {
        return $exploded[0] . "." . $exploded[1] . str_replace("0-", "-", $exploded[2]);
    }
    return $number;
}
/*
 * arg0: string, oops
 * arg1: string, return value contain hardware name
*/
function FindBIOS($raw) {
    $hwname = "Unknown";
    $first = preg_split("/[\n]/", $raw);
    $bound = count($first);
    for ($i = 0;$i < $bound;$i++) {
        $guess = 0;
        $bt = $first[$i];
        if (preg_match("/Hardware name: (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/Hardware name:[ ]*$/", $bt)) $hwname = "Unknown";
    }
    if (preg_match("/(.*)\(/", $hwname, $matches)) $hwname = $matches[1];
    while (preg_match("/(.*)[\W \t]+$/", $hwname, $matches)) $hwname = $matches[1];
    return $hwname;
}
/* arg1: raw oops
 * return: founded hwname
 * get hw name at and of pid line
*/
function FindHW($raw) {
    $hwname = "Unknown";
    $first = preg_split("/[\n]/", $raw);
    $bound = count($first);
    for ($i = 0;$i < $bound;$i++) {
        $guess = 0;
        $bt = $first[$i];
        if (preg_match("/^Hardware name: (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/^Pid: .*ainted.* #[0-9]+\) (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/^Pid: .*ainted.* #[0-9]+\-Ubuntu\) (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/^Pid: .*ainted.* #[0-9]+\-Ubuntu (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/^Pid: .*ainted.* #[0-9]+\~pre[0-9]\-Ubuntu (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/^Pid: .*ainted.*64 #[0-9]+ (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/^Pid: .*ainted.* #[0-9]+ (.*)/", $bt, $matches)) $hwname = $matches[1];
        if (preg_match("/^(.*) EIP:/", $hwname, $matches)) $hwname = $matches[1];
        if (preg_match("/^(.*) \[\</", $hwname, $matches)) $hwname = $matches[1];
        if (preg_match("/EIP:/", $hwname)) $hwname = "";
        $hwname = preg_replace("/^ /", "", $hwname);
    }
    return $hwname;
}
/*
 * arg0: string, oops
 * return: string, distro name
 * description: return array oops {distro, version}
*/
function GuessDistro($raw) {
    if (preg_match("/\.fc([0-9]+)\./", $raw, $match)) return isset($match[1]) ? "Fedora $match[1]" : "Fedora";
    if (preg_match("/\.([0-9]+)mdx$/", $raw, $match)) return isset($match[1]) ? "Mandriva $match[1]" : "Mandriva";
    if (preg_match("/\.el([0-9]+)\./", $raw, $match)) return isset($match[1]) ? "RHEL $match[1]" : "RHEL";
    if (preg_match("/debian/i", $raw)) return "Debian";
    if (preg_match("/ubuntu/i", $raw)) return "Ubuntu";
    if (preg_match("/gentoo/i", $raw)) return "Gentoo";
    return "Unknown";
}
/* find tained chars in oops
 * arg1: raw oops
 * return: tainted string
*/
function FindTainted($raw) {
    if (preg_match("/Tainted:[ ]*([A-Z ]*)/", $raw, $matches)) return str_replace(" ", "", $matches[1]);
    else return "";
}
/* translate chars to strings.
 * Strings source: kernel/panic.c
 * arg1: tainted chars
*/
function TranslateTainted($tstring) {
    $out = "";
    if (strpos($tstring, "P") !== False) $out.= "<li>P - Proprietary module has been loaded</li>";
    if (strpos($tstring, "G") !== False) $out.= "<li>G - All loaded modules have GPL or compatible license</li>";
    if (strpos($tstring, "F") !== False) $out.= "<li>F - Module has been forcibly loaded</li>";
    if (strpos($tstring, "S") !== False) $out.= "<li>S - SMP with CPUs not designed for SMP</li>";
    if (strpos($tstring, "R") !== False) $out.= "<li>R - User forced a module unload</li>";
    if (strpos($tstring, "M") !== False) $out.= "<li>M - System experienced a machine check exception</li>";
    if (strpos($tstring, "B") !== False) $out.= "<li>B - System has hit bad_page</li>";
    if (strpos($tstring, "U") !== False) $out.= "<li>U - Userspace-defined naughtiness</li>";
    if (strpos($tstring, "D") !== False) $out.= "<li>D - Kernel has oopsed before</li>";
    if (strpos($tstring, "A") !== False) $out.= "<li>A - ACPI table overridden</li>";
    if (strpos($tstring, "W") !== False) $out.= "<li>W - Taint on warning</li>";
    if (strpos($tstring, "C") !== False) $out.= "<li>C - modules from drivers/staging are loaded</li>";
    if (strpos($tstring, "I") !== False) $out.= "<li>I - Working around severe firmware bug</li>";
    if (strpos($tstring, "O") !== False) $out.= "<li>O - Out-of-tree module has been loaded</li>";
    if (strpos($tstring, "E") !== False) $out.= "<li>E - Unsigned module has been loaded</li>";
    if (strlen($out) > 0) return $out;
    else return "";
}
/* return arch
 * arg1: raw oops
 * return: arch string
*/
function FindArch($raw) {
    if (strpos($raw, ".x86_64") !== False ||
        strpos($raw, "-amd64") !== False ||
        strpos($raw, "RIP: ") !== False)
           return "x86_64";
    if (strpos($raw, "x86") !== False ||
        strpos($raw, ".i386") !== False ||
        strpos($raw, "-386") !== False ||
        strpos($raw, ".i486") !== False ||
        strpos($raw, "-486") !== False ||
        strpos($raw, ".i586") !== False ||
        strpos($raw, "-586") !== False ||
        strpos($raw, ".i686") !== False ||
        strpos($raw, "-686") !== False ||
        strpos($raw, "EIP: ") !== False)
           return "x86";
    if (strpos($raw, "NIP:") !== False) {
        if (preg_match("/NIP: [0-9a-fA-F]{16}[ ]/", $originalRaw))
            return "powerpc64";
        else return "powerpc";
    }
    return "Unknown";
}
# if function is on fide list, return 1
# arg1: function name
# return 1 if function is on black list
function BackTraceHide($func) {
    if (preg_match("/^die$/", $func)) {
        return 1;
    }
    if (preg_match("/^__die$/", $func)) {
        return 1;
    }
    if (preg_match("/^show_trace_log_lvl/", $func)) {
        return 1;
    }
    if (preg_match("/^debug_show_held_locks/", $func)) {
        return 1;
    }
    if (preg_match("/^show_stack_log_lvl/", $func)) {
        return 1;
    }
    if (preg_match("/^show_registers/", $func)) {
        return 1;
    }
    if (preg_match("/^do_trap/", $func)) {
        return 1;
    }
    if (preg_match("/^do_invalid_op/", $func)) {
        return 1;
    }
    if (preg_match("/^error_code/", $func)) {
        return 1;
    }
    if (preg_match("/^show_stack/", $func)) {
        return 1;
    }
    if (preg_match("/^_show_stack/", $func)) {
        return 1;
    }
    if (preg_match("/^stext/", $func)) {
        return 1;
    }
    if (preg_match("/^_stext/", $func)) {
        return 1;
    }
    if (preg_match("/^ia64_leave_kernel/", $func)) {
        return 1;
    }
    if (preg_match("/^ia64_do_page_fault/", $func)) {
        return 1;
    }
    if (preg_match("/^dump_stack/", $func)) {
        return 1;
    }
    if (preg_match("/^dump_trace/", $func)) {
        return 1;
    }
    if (preg_match("/^show_trace/", $func)) {
        return 1;
    }
    if (preg_match("/^show_regs/", $func)) {
        return 1;
    }
    if (preg_match("/^__sched_text_start/", $func)) {
        return 1;
    }
    if (preg_match("/hardirqs last /", $func)) {
        return 1;
    }
    if (preg_match("/softirqs last /", $func)) {
        return 1;
    }
    if (preg_match("/\#[0-9]+:  (.*){.*}, at: /", $func)) {
        return 1;
    }
    if (preg_match("/^do_page_fault/", $func)) {
        return 1;
    }
    if (preg_match("/^try_stack_unwind/", $func)) {
        return 1;
    }
    if (preg_match("/^warn_slowpath_common/", $func)) {
        return 1;
    }
    if (preg_match("/^warn_slowpath_null/", $func)) {
        return 1;
    }
    if (preg_match("/^warn_slowpath_fmt/", $func)) {
        return 1;
    }
    return 0;
}
function MarkupFunction($kernel, $archt, $function, $type = "") {
    global $db_cache;
    global $kffcache;
    unset($gname, $gline, $kernels, $args);
    // replace "? "
    $function = str_replace("? ", "", $function);
    // prepare function name and line offset
    $args = explode("+", $function);
    if (preg_match("/^_Z/", $args[0])) {
        exec("c++filt " . escapeshellarg($args[0]), $results);
        return rtrim($results[0]);
    }
    // hide function?
    if (BackTraceHelper($args[0], $type)) return "";
    // if function have offset, get line from it
    if (isset($args[1])) {
        $tmp = explode("/", $args[1]);
        $line = hexdec($tmp[0]);
    } else $line = 0;
    // prepare kernel version
    $kernel = explode(".", $kernel);
    $kernels = "";
    foreach ($kernel as $key => $value) $kernels.= $value . ".";
    $kernels = substr($kernels, 0, strlen($kernels) - 1);
    $last = 0;
    if (isset($db_cache["function"][$args[0]]) && isset($db_cache["kernel"][$kernels]) && isset($kffcache[$db_cache["function"][$args[0]]])) {
        $id = $db_cache["function"][$args[0]];
        $kernel_id = $db_cache["kernel"][$kernels];
        foreach ($kffcache[$id] as $key => $value) {
            if ($key < $kernel_id) $last = $key;
            else break;
        }
    }
    if (!isset($last) || !isset($id) || !isset($kffcache[$id][$last]["fileName"])) return $args[0];
    $gname = $kffcache[$id][$last]["fileName"];
    $gline = $kffcache[$id][$last]["line"];
    if ($archt == "x86_64") $arch = "x86";
    else if ($archt == "powerpc64") $arch = "powerpc";
    else $arch = $archt;
    if (strpos($gname, "arch") !== false && $arch != "Unknown" && strpos($gname, $arch) === False) {
        foreach ($kffcache[$id] as $key => $value) {
            if ($key < $kernel_id && strpos($value["fileName"], $arch) !== false) $last = $key;
        }
        $gname = $kffcache[$id][$last]["fileName"];
        $gline = $kffcache[$id][$last]["line"];
    }
    return '<a href="https://git.kernel.org/cgit/linux/kernel/git/stable/linux-stable.git/tree/' . $gname . '?id=v' . $kernels . '#n' . $gline . '">' . $args[0] . '</a>';
}
function CleanUpFunction($function, $type) {
    // replace "? "
    $function = str_replace("? ", "", $function);
    // prepare function name and line offset
    $args = explode("+", $function);
    if (preg_match("/^_Z/", $args[0])) {
        exec("c++filt " . escapeshellarg($args[0]), $results);
        return rtrim($results[0]);
    }
    // hide function?
    if (BackTraceHelper($args[0], $type)) return "";
    // if function have offset, get line from it
    return $args[0];
}
/* find all registers in oops
 * arg1: raw oops
 * retrun: array of register and content
*/
function FindRegisters($raw) {
    $line = preg_split("/[\n]/", $raw);
    $bound = count($line);
    unset($registers);
    for ($i = 0;$i < $bound;$i++) {
        if (preg_match_all("/[^\/](knl)?[A-Z][0-9A-Z]{1,}[:][ ]*([0-9a-fA-F]{2})+(\([0-9a-fA-F]{2,}\))?/", $line[$i], $matches)) {
            if (preg_match("/[^\/][A-Z][0-9A-Z]{1,}[:]([ ]([0-9a-fA-F]{2})+(\([0-9a-fA-F]{2,}\))?){2,}/", $line[$i])) {
                $temp = explode(": ", $line[$i]);
                $registers[$temp[0]] = $temp[1];
            } else {
                foreach ($matches[0] as $key => $value) {
                    $temp = explode(":", str_replace(" ", "", $value));
                    if (strpos($temp[0], "LNX") !== False) continue; // not register
                    if (strpos($temp[0], "PNP") !== False) continue; // not register
                    if (strpos($temp[0], "BUG") !== False) continue; // not register
                    $registers[$temp[0]] = $temp[1];
                }
            }
        }
    }
    return isset($registers) ? $registers : False;
    
}
/* find stack
 * arg1: raw oops
 * retrun: array of stack content
*/
function FindStack($raw) {
    $line = preg_split("/[\n]/", $raw);
    $bound = count($line);
    $instack = False;
    unset($stack);
    for ($i = 0;$i < $bound;$i++) {
        if ($instack) {
            if (preg_match_all("/([0-9a-fA-F]{4,})/", $line[$i], $matches)) {
                foreach ($matches[0] as $key => $value) $stack[] = $value;
            } else {
                $instack = False;
                return $stack;
            }
        } else {
            if (strpos($line[$i], "Stack:") !== False) {
                $instack = True;
            }
        }
    }
    return False;
}
/* find info about xIP position in bug time
 * arg1: raw oops
 * return: reformated string
*/
function FindXIP($raw) {
    $line = preg_split("/[\n]/", $raw);
    $bound = count($line);
    unset($ret, $out);
    for ($i = 0;$i < $bound;$i++) {
        if (preg_match("/([ER]IP:.*)/", $line[$i], $matches)) {
            $ret[] = $matches[0];
        } elseif (preg_match("/([ER]?IP is at.*)/", $line[$i], $matches)) {
            $ret[] = $matches[0];
        }
    }
    if (isset($ret)) {
        foreach ($ret as $key => $value) {
            preg_match("/([^ ]*)[+](0x[0-9a-f]+\/0x[0-9a-f]+)/", $value, $matches);
            if (isset($matches[1]) && isset($matches[2])) {
                $out["fname"] = $matches[1];
                $out["params"] = $matches[2];
            }
            if (preg_match("/[:][<\[]{1,2}(0x)?([0-9a-z]+)[>\]]{1,2}/", $value, $matches) && isset($matches[2])) $out["ipaddr"] = $matches[2];
        }
    }
    return isset($out) ? $out : False;
}
function FixBacktrace($backtrace, $type, $tainted, &$bt) {
    $first = preg_split("/[\n]/", $backtrace);
    $f = 0;
    $first_known = "";
    $first_guess = "";
    if ($first[0] === "sysfs_add_one" && $type === "WARN_ON statement") $type = "sysfs duplicate";
    $bound = count($first);
    while ($f < $bound) {
        $guess = 0;
        $bt = $first[$f];
        $bt = preg_replace("/\*/", "", $bt);
        if (preg_match("/\?/", $bt)) {
            $bt = preg_replace("/^\? /", "", $bt);
            $guess = 1;
        }
        $bt = preg_replace("/^ /", "", $bt);
        $helper;
        $helper = BackTraceHelper($bt, $type, $tainted);
        if ($guess == 0) $guess = BackTraceGuess($bt, $type, $tainted, $first_guess);
        if ($first_known === "" && $guess == 0 && $helper == 0) $first_known = $bt;
        if ($first_guess === "" && $guess == 1 && $helper == 0) $first_guess = $bt;
        $f = $f + 1;
        if ($first_known === "sysfs_add_one" && $type === "WARN_ON statement") {
            $type = "sysfs duplicate";
            $first_known = "";
        };
    }
    if ($first_known === "") $first_known = $first_guess;
    # special fgrlx hack
    if ($first_known === "task_has_capability" && $tainted == 1 && $type === "BUG statement") $first_known = "firegl_ioctl";
    if ($first_known === "task_has_capability" && $tainted == 1 && $type === "unknown") $first_known = "firegl_ioctl";
    if ($first_known === "cap_capable" && $tainted == 1 && $type === "kernel page fault") $first_known = "firegl_ioctl";
    if ($first_known === "handle_mm_fault" && preg_match("/VBoxDrvLinuxIOCtl/", $backtrace)) $first_known = "VBoxDrvLinuxIOCtl";
    if ($first_known === "lock_page" && preg_match("/VBoxDrvLinuxIOCtl/", $backtrace)) $first_known = "VBoxDrvLinuxIOCtl";
    if ($first_known === "free_memtype" && preg_match("/nv_set_page_attrib_cached/", $backtrace)) $first_known = "nv_set_page_attrib_cached";
    if ($first_known === "free_memtype" && preg_match("/nv_vm_free_pages/", $backtrace)) $first_known = "nv_vm_free_pages";
    if ($first_known === "need_resched" && preg_match("/rfkill_force_state/", $backtrace) && preg_match("/NULL pointer/", $type)) $first_known = "rfkill_force_state";
    if ($first_known === "need_resched" && preg_match("/rfkill_force_state/", $backtrace) && preg_match("/unknown/", $type)) $first_known = "rfkill_force_state";
    return $first_known;
}
function FindGuilty($parsed, $raw) {
    global $wpdb;
    if (isset($parsed["bugline"]) && strpos($parsed["bugline"], ":") !== False && !strpos($parsed["bugline"], "#")) {
        if (preg_match("/\[(.*)\]/", $parsed["bugline"], $match)) $ret["module"] = CleanUpModule($match[1]);
        if (preg_match("/([^ ]*\.[ch])/", $parsed["bugline"], $match)) $ret["file"] = $match[1];
        if (preg_match("/([^ ]*)\+0x/", $parsed["bugline"], $match)) $ret["function"] = $match[1];
    }
    if (!isset($ret["function"])) {
        $line = preg_split("/[\n]/", $raw);
        $bound = count($line);
        for ($i = 0;$i < $bound;$i++) {
            if (strpos($line[$i], "#") !== False) continue;
            if (preg_match("/[ER]IP.*[ ]([^ ]+)\+0x[^ ]*([ ]\[(.*)\])?/", $line[$i], $match)) {
                if (isset($match[3])) $ret["module"] = CleanUpModule($match[3]);
                if (isset($match[1])) $ret["function"] = $match[1];
                if (isset($db_cache["function"][$ret["function"]]) && isset($db_cache["kernel"][$parsed["kernel"]])) {
                    $id = $db_cache["function"][$ret["function"]];
                    $kernel_id = $db_cache["kernel"][$parsed["kernel"]];
                    foreach ($kffcache[$id] as $key => $value) {
                        if ($key < $kernel_id) $last = $key;
                        else break;
                    }
                    $ret["file"] = $kffcache[$id][$last]["fileName"];
                }
            }
            if ($parsed["arch"] == "x86_64") $arch = "x86";
            else $arch = $parsed["arch"];
            if (isset($ret["file"]) && (strpos($ret["file"], "arch") !== false && $parsed["arch"] != "Unknown" && strpos($ret["file"], $parsed["arch"]) === False)) {
                $id = $db_cache["function"][$ret["function"]];
                $kernel_id = $db_cache["kernel"][$parsed["kernel"]];
                if (is_array($kffcache[$id])) {
                    foreach ($kffcache[$id] as $key => $value) {
                        if ($key < $kernel_id && strpos($value["fileName"], $parsed["arch"]) !== false) $last = $key;
                    }
                    $ret["file"] = $kffcache[$id][$last]["fileName"];
                }
            }
        }
        if (preg_match("/caller is[ ]([^ ]+)\+0x[^ ]*([ ]\[(.*)\])?/", $raw, $match)) {
            if (isset($match[1])) $ret["function"] = $match[1];
            if (isset($match[3])) $ret["module"] = CleanUpModule($match[3]);
        }
    }
    $ret["driver"] = FindDriver($raw);
    if (preg_match("/^[0-9]+$/", $ret["driver"])) $ret["driver"] = "";
    return $ret;
}
/*
 * certain functions tend to be messengers of bugs, not the cause of the bug.
 * this function identifies these helpers, which then in turn helps the
 * analysis code to catalog based on real culprit
*/
function BackTraceHelper($func, $type = "", $tainted = "") {
    if (BackTraceHide($func, "") > 0 ||
           $func == "" ||
           $func === "ioread8" ||
           $func === "list_add" ||
           $func === "stext" ||
           $func === "_stext" ||
           $func === "_etext" ||
           $func === "schedule" ||
           $func === "debug_smp_processor_id" ||
           $func === "trace_hardirqs_on" ||
           $func === "__list_add" ||
           $func === "__might_sleep" ||
           $func === "kref_get" ||
           $func === "kobject_get" ||
           $func === "kobject_add" ||
           $func === "handle_write_count_underflow" ||
           $func === "klist_del" ||
           $func === "device_del" ||
           $func === "device_unregister" ||
           $func === "kmem_cache_free" ||
           $func === "mutex_lock_nested" ||
           $func === "mutex_lock" ||
           $func === "__mutex_lock_common" ||
           $func === "__mutex_lock_slowpath" ||
           $func === "__schedule_bug" ||
           $func === "__cond_resched" ||
           $func === "_cond_resched" ||
           $func === "cond_resched" ||
           $func === "kunmap" ||
           $func === "strlen" ||
           $func === "strlcpy" ||
           $func === "kfree" ||
           $func === "kfree_skbmem" ||
           $func === "dma_alloc_coherent" ||
           $func === "kunmap_atomic" ||
           $func === "smp_call_function_single" ||
           $func === "smp_call_function_mask" ||
           $func === "native_smp_call_function_mask" ||
           $func === "check_flags" ||
           $func === "vgacon_scroll" ||
           $func === "skb_over_panic" ||
           $func === "skb_under_panic" ||
           $func === "_rdmsr_on_cpu" ||
           $func === "_wrmsr_on_cpu" ||
           $func === "spin_bug" ||
           $func === "_spin_lock" ||
           $func === "_raw_spin_lock" ||
           $func === "_spin_lock_irqsave" ||
           $func === "set_fail" ||
           $func === "sysctl_check_table" ||
           $func === "register_sysctl_table" ||
           $func === "__register_sysctl_paths" ||
           $func === "register_sysctl_paths" ||
           $func === "warn_on_slowpath" ||
           $func === "warn_slowpath_null" ||
           $func === "warn_slowpath" ||
           $func === "warn_slowpath_common" ||
           $func === "warn_slowpath_fmt" ||
           $func === "usb_kill_urb" ||
           $func === "check_for_stack" ||
           $func === "debug_dma_map_sg" ||
           $func === "debug_dma_map_page" ||
           ($func === "pci_map_single" && $type === "WARN_ON statement") ||
           ($func === "dma_map_single" && $type === "WARN_ON statement") ||
           ($func === "acpi_ut_exception" && $type === "WARN_ON statement") ||
           ($func === "kmem_cache_destroy" && $type === "WARN_ON statement") ||
           ($func === "__alloc_pages_slowpath" && $type === "WARN_ON statement") ||
           ($func === "register_netdevice" && $tainted == "1") ||
           ($func === "register_netdev" && $tainted == "1") ||
           ($func === "mark_buffer_dirty" && $type === "WARN_ON statement") ||
           ($func === "_spin_unlock_irqrestore" && $type === "soft lockup") ||
           ($func === "_spin_unlock_irq" && $type === "soft lockup") ||
           ($func === "native_read_tsc" && $type === "soft lockup") ||
           ($func === "__delay" && $type === "soft lockup") ||
           ($func === "__udelay" && $type === "soft lockup") ||
           ($func === "__const_udelay" && $type === "soft lockup") ||
           ($func === "pci_bus_read_config_dword" && $type === "soft lockup") ||
           ($func === "pci_bus_write_config_dword" && $type === "soft lockup") ||
           ($func === "ioread32" && $type === "soft lockup") ||
           ($func === "__alloc_pages" && $type === "invalid context") ||
           ($func === "kmem_cache_alloc" && $type === "invalid context") ||
           ($func === "alloc_pages_current" && $type === "invalid context") ||
           ($func === "copy_to_user" && $type === "invalid context") ||
           ($func === "copy_from_user" && $type === "invalid context") ||
           ($func === "__get_free_pages" && $type === "invalid context") ||
           ($func === "__kmalloc" && $type === "invalid context") ||
           ($func === "rtnl_lock" && $type === "invalid context") ||
           ($func === "wait_for_completion_timeout" && $type === "invalid context") ||
           ($func === "down_read" && $type === "invalid context") ||
           ($func === "list_del" && $type === "BUG statement") ||
           ($func === "list_del" && $type === "WARN_ON statement") ||
           ($func === "mark_buffer_dirty" && $type === "WARN_ON statement") ||
           ($func === "bad_io_access" && $type === "WARN_ON statement") ||
           ($func === "local_bh_enable" && $type === "WARN_ON statement") ||
           ($func === "cond_resched_softirq" && $type === "WARN_ON statement") ||
           ($func === "ioread" && $type === "WARN_ON statement") ||
           ($func === "iowrite8" && $type === "WARN_ON statement") ||
           ($func === "sysctl_head_finish" && $type === "sysctl check") ||
           ($func === "sysctl_set_parent" && $type === "sysctl check") ||
           ($func === "sysctl_check_lookup" && $type === "sysctl check") ||
           ($func === "dma_free_coherent" && $type === "WARN_ON statement") ||
           ($func === "unregister_sysctl_table" && $type === "WARN_ON statement") ||
           ($func === "__ioremap_caller" && $type === "WARN_ON statement") ||
           ($func === "ioremap_nocache" && $type === "WARN_ON statement") ||
           ($func === "unlock_page" && $type === "BUG statement") ||
           ($func === "ioremap_wc" && $type === "WARN_ON statement") ||
           ($func === "iomem_map_sanity_check" && $type === "WARN_ON statement") ||
           ($func === "_local_bh_enable_ip" && $type === "WARN_ON statement") ||
           ($func === "_spin_unlock_bh" && $type === "WARN_ON statement") ||
           ($func === "local_bh_enable_ip" && $type === "WARN_ON statement") ||
           ($func === "kobject_put" && $type === "WARN_ON statement") ||
           ($func === "put_device" && $type === "WARN_ON statement") ||
           ($func === "__lock_acquire" && $type === "kernel NULL pointer") ||
           ($func === "lock_acquire" && $type === "kernel NULL pointer") ||
           ($func === "setup_irq" && $type === "IRQ handler mismatch") ||
           ($func === "request_irq" && $type === "IRQ handler mismatch") ||
           ($func === "i915_gem_idle" && $type === "WARN_ON statement") ||
           ($func === "__debug_object_init" && $type === "WARN_ON statement") ||
           ($func === "debug_object_init" && $type === "WARN_ON statement") ||
           ($func === "init_timer" && $type === "WARN_ON statement") ||
           ($func === "check_unmap" && $type === "WARN_ON statement") ||
           ($func === "debug_dma_unmap_page" && $type === "WARN_ON statement") ||
           ($func === "pci_unmap_page" && $type === "WARN_ON statement") ||
           ($func === "dma_unmap_single" && $type === "WARN_ON statement") ||
           ($func === "dma_unmap_page" && $type === "WARN_ON statement") ||
           ($func === "skb_dma_unmap" && $type === "WARN_ON statement") ||
           ($func === "pci_unmap_page.clone.0" && $type === "WARN_ON statement") ||
           (preg_match("/T\.[0-9]+/", $func) && $type === "WARN_ON statement") ||
           ($func === "check_sync" && $type === "WARN_ON statement") ||
           ($func === "debug_dma_sync_single_for_cpu" && $type === "WARN_ON statement") ||
           ($func === "debug_dma_sync_single_for_device" && $type === "WARN_ON statement") ||
           ($func === "pci_dma_sync_single_for_cpu" && $type === "WARN_ON statement") ||
           ($func === "pci_dma_sync_single_for_device" && $type === "WARN_ON statement") ||
           ($func === "dma_sync_single_for_device" && $type === "WARN_ON statement") ||
           ($func === "dma_sync_single_for_cpu" && $type === "WARN_ON statement") ||
           ($func === "pci_dma_sync_single_for_cpu.clone.0" && $type === "WARN_ON statement") ||
           ($func === "pci_dma_sync_single_for_cpu.clone.1" && $type === "WARN_ON statement") ||
           ($func === "debug_dma_sync_single_range_for_cpu" && $type === "WARN_ON statement") ||
           ($func === "__ticket_spin_lock" && $type === "kernel NULL pointer") ||
           # sysfs duplication handling
           ($func === "sysfs_add_one" && $type === "sysfs duplicate") ||
           ($func === "sysfs_create_link" && $type === "sysfs duplicate") ||
           ($func === "sysfs_do_create_link" && $type === "sysfs duplicate") ||
           ($func === "device_add" && $type === "sysfs duplicate") ||
           ($func === "device_register" && $type === "sysfs duplicate") ||
           ($func === "device_create" && $type === "sysfs duplicate") ||
           ($func === "device_rename" && $type === "sysfs duplicate") ||
           ($func === "device_create_dir" && $type === "sysfs duplicate") ||
           ($func === "device_create_vargs" && $type === "sysfs duplicate") ||
           ($func === "kobject_add_internal" && $type === "sysfs duplicate") ||
           ($func === "kobject_add_varg" && $type === "sysfs duplicate") ||
           ($func === "kobject_add" && $type === "sysfs duplicate") ||
           ($func === "kobject_init_and_add" && $type === "sysfs duplicate") ||
           ($func === "kobject_add_internal" && $type === "sysfs duplicate") ||
           ($func === "kobject_register" && $type === "sysfs duplicate") ||
           ($func === "sysfs_create_dir" && $type === "sysfs duplicate") ||
           ($func === "sysfs_add_file" && $type === "sysfs duplicate") ||
           ($func === "bus_add_device" && $type === "sysfs duplicate") ||
           ($func === "bus_add_driver" && $type === "sysfs duplicate") ||
           ($func === "driver_register" && $type === "sysfs duplicate") ||
           ($func === "driver_sysfs_add" && $type === "sysfs duplicate") ||
           ($func === "create_dir" && $type === "sysfs duplicate") ||
           ($func === "sk_free" && $tainted == "1") ||
           ($func === "might_fault") ||
           ($func === "__slab_free") ||
           ($func === "strnlen") ||
           ($func === "system_call_fastpath") ||
           (($func === "pci_unmap_single") && ($type === "WARN_ON statement")) ||
           (($func === "memcmp") && ($type === "soft lockup")) ||
           (($func === "__alloc_pages_nodemask") && ($type === "WARN_ON statement")) ||
           (($func === "alloc_pages_current") && ($type === "WARN_ON statement")) ||
           (($func === "alloc_page_interleave") && ($type === "WARN_ON statement")) ||
           (($func === "__get_free_pages") && ($type === "WARN_ON statement")) ||
           (($func === "__kmalloc") && ($type === "WARN_ON statement")) ||
           (($func === "acpi_ps_complete_op") && ($type === "invalid context")) ||
           (($func === "acpi_ps_parse_loop") && ($type === "invalid context")) ||
           (($func === "acpi_ps_parse_aml") && ($type === "invalid context")) ||
           (($func === "acpi_ps_complete_op") && ($type === "atomic schedule")) ||
           (($func === "acpi_ps_parse_loop") && ($type === "atomic schedule")) ||
           (($func === "acpi_ps_parse_aml") && ($type === "atomic schedule")) ||
           (($func === "__schedule") && ($type === "atomic schedule")) ||
           (($func === "__wake_up") && ($type === "atomic schedule")) ||
           (($func === "__wake_up") && ($type === "invalid context")) ||
           (($func === "__alloc_pages_internal") && ($type === "invalid context")) ||
           (($func === "kmem_cache_alloc_notrace") && ($type === "invalid context")) ||
           (($func === "schedule_timeout") && ($type === "atomic schedule")) ||
           (($func === "wait_for_common") && ($type === "invalid context")) ||
           (($func === "wait_for_common") && ($type === "atomic schedule")) ||
           (($func === "wait_for_completion_timeout") && ($type === "atomic schedule")) ||
           (($func === "put_page") && ($type === "kernel NULL pointer")) ||
           (($func === "_request_firmware") && ($type === "kernel NULL pointer")) ||
           (($func === "_request_firmware") && ($type === "invalid context")) ||
           (($func === "put_page") && ($type === "kernel page fault")) ||
           (($func === "kmem_cache_alloc") && ($type === "soft lockup")) ||
           (($func === "dma_map_single_attrs") && ($type === "WARN_ON statement")) ||
           (($func === "enable_irq") && ($type === "WARN_ON statement")) ||
           (($func === "rt_spin_lock_fastlock")) ||
           (($func === "rt_spin_lock_slowlock")) ||
           (($func === "rt_spin_lock")) ||
           (($func === "sysfs_add_file_mode") && ($type === "sysfs duplicate")) ||
           (($func === "sysfs_create_file") && ($type === "sysfs duplicate")) ||
           (($func === "device_create_file") && ($type === "sysfs duplicate")) ||
           (($func === "class_create_file") && ($type === "sysfs duplicate")))
           return 1;
    return 0;
}
function BackTraceGuess($func, $type, $taitned, $guess) {
    if ((($func === "dma_map_single_attrs") && ($type === "WARN_ON statement")) ||
        (($func === "usb_hcd_submit_urb") && ($type === "WARN_ON statement")) ||
        (($func === "usb_submit_urb") && ($type === "WARN_ON statement")) ||
        (($func === "usb_start_wait_urb") && ($type === "WARN_ON statement")) ||
        (($func === "usb_bulk_msg") && ($type === "WARN_ON statement")) ||
        (($func === "usb_control_msg") && ($type === "WARN_ON statement")) ||
        (($func === "debug_dma_free_coherent") && ($type === "WARN_ON statement")) ||
        (($func === "drm_helper_initial_config") && ($type === "WARN_ON statement")) ||
        (($func === "page_fault") && ($type === "invalid context")) ||
        (($func === "wait_for_common") && ($type === "invalid context")) ||
        (($func === "wait_for_completion") && ($type === "invalid context")) ||
        (($func === "set_cpus_allowed_ptr") && ($type === "invalid context")) ||
        (($func === "lock_sock") && ($type === "invalid context")) ||
        (($func === "lock_sock_nested") && ($type === "invalid context")) ||
        (($func === "queue_delayed_work_on") && ($type === "invalid context")) ||
        (($func === "queue_delayed_work") && ($type === "invalid context")) ||
        (($func === "schedule_delayed_work") && ($type === "invalid context")) ||
        (($func === "sg_miter_next") && ($type === "invalid context")) ||
        (($func === "ohci_enable") && ($type === "kernel NULL pointer")) ||
        (($func === "acpi_os_allocate") && ($type === "WARN_ON statement")) ||
        (($func === "__init_work") && ($type === "WARN_ON statement")) ||
        (($func === "__rt_spin_lock")) ||
        (($func === "kmap_atomic_prot")) ||
        (($func === "debug_kmap_atomic")) ||
        (($func === "kmap_atomic")) ||
        (($func === "check_unmap")) ||
        (($func === "debug_dma_unmap_page")) ||
        (($func === "match_number") && ($type === "WARN_ON statement")) ||
        (($func === "match_int") && ($type === "WARN_ON statement")) ||
        (($func === "parse_options") && ($type === "WARN_ON statement")) ||
        (($func === "__wake_up") && ($type === "invalid context") && ($guess === "rt_spin_lock_fastlock")) ||
        (($func === "__mod_timer") && ($type === "invalid context") && ($guess === "debug_object_activate")) ||
        (($func === "mod_timer") && ($type === "invalid context") && ($guess === "debug_object_activate")) ||
        (($func === "add_timer") && ($type === "invalid context") && ($guess === "debug_object_activate")) ||
        (($func === "__mod_timer") && ($type === "invalid context") && ($guess === "debug_activate")) ||
        (($func === "mod_timer") && ($type === "invalid context") && ($guess === "debug_activate")) ||
        (($func === "add_timer") && ($type === "invalid context") && ($guess === "debug_activate")) ||
        (($func === "proc_register") && ($type === "WARN_ON statement")) ||
        (($func === "proc_mkdir_mode") && ($type === "WARN_ON statement") && ($guess === "proc_register")) ||
        (($func === "proc_mkdir") && ($type === "WARN_ON statement") && ($guess === "proc_register")) ||
        (($func === "create_proc_entry") && ($type === "WARN_ON statement") && ($guess === "proc_register")) ||
        (($func === "register_handler_proc") && ($type === "WARN_ON statement") && ($guess === "proc_register")))
        return 1;
    return 0;
}
function MarkUpBugline($line, $kernel, $arch) {
    if (strpos($line, ".c:") !== False) {
        if (preg_match("/([^ ]+\.c):([0-9]+)[ ]+([^\+]*)\+/", $line, $match))
           return "<a href=\"https://git.kernel.org/cgit/linux/kernel/git/stable/linux-stable.git/tree/" . $match[1] . "?id=v" . $kernel . "#n" . $match[2] . "\">" . $match[3] . "</a>";
        else return False;
    } else return False;
}
/* return last used sysfs file
 * arg1: raw oops
 * return: last used file
*/
function FindLastSysFS($raw) {
    $line = preg_split("/[\n]/", $raw);
    $bound = count($line);
    for ($i = 0;$i < $bound;$i++) {
        if (preg_match("/^last sysfs file: (.*)/", $line[$i], $match)) return $match[1];
    }
    return False;
}
/* calc hash for reduce duplicity
 * arg1: parsed oops
 * return: if oops ok -> hash, false in other weays
*/
function CalcUniqHash($parsed) {
    if (!isset($parsed["version"]) ||$parsed["version"] == "" || !isset($parsed["bugline"]) || $parsed["bugline"] == "") return False;
    $hash = hash_init("sha256");
    hash_update($hash, $parsed["version"]);
    hash_update($hash, $parsed["bugline"]);
    return hash_final($hash);
}
/* memmory usage helper */
function convert($size) {
    $unit = array('b', 'Kb', 'Mb', 'Gb', 'Tb', 'Pb');
    return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
}
/* return size of allocated memmory
 * arg1: variable
 * return: mem size
*/
function sizeofvar($var) {
    $start_memory = memory_get_usage();
    $var = unserialize(serialize($var));
    return convert(memory_get_usage() - $start_memory);
}
/* find driver where caused oops
 * arg1: raw oops
 * return: driver name
*/
function FindDriver($raw) {
    $driver = "";
    $first = preg_split("/[\n]/", $raw);
    $first_known = "";
    $first_guess = "";
    $bound = count($first);
    for ($i = 0;$i < $bound;$i++) {
        $guess = 0;
        $bt = $first[$i];
        if (preg_match("/vortex_tx_timeout/", $bt)) $driver = "3c59x";
        if (preg_match("/usbnet_tx_timeout/", $bt)) $driver = "usbnet";
        if (preg_match("/rhine_tx_timeout/", $bt)) $driver = "via-rhine";
        if (preg_match("/bnep_net_timeout/", $bt)) $driver = "bnep";
        if (preg_match("/rtl8139_tx_timeout/", $bt)) $driver = "8139too";
        if (preg_match("/rtl8169_tx_timeout/", $bt)) $driver = "r8169";
        if (preg_match("/ei_tx_timeout/", $bt)) $driver = "8390";
        if (preg_match("/nv_tx_timeout/", $bt)) $driver = "forcedeth";
        if (preg_match("/sundance_reset/", $bt)) $driver = "sundance";
        if (preg_match("/hso_net_tx_timeout/", $bt)) $driver = "hso";
        if (preg_match("/orinoco_tx_timeout/", $bt)) $driver = "orinoco";
        if (preg_match("/ath_tx_timeout/", $bt)) $driver = "ath_pci";
        if (preg_match("/islpci_eth_tx_timeout/", $bt)) $driver = "prism54";
        if (preg_match("/rtl8150_tx_timeout/", $bt)) $driver = "rtl8150";
        if (preg_match("/ipg_tx_timeout/", $bt)) $driver = "ipg";
        if (preg_match("/cp_tx_timeout/", $bt)) $driver = "8139cp";
        if (preg_match("/tulip_tx_timeout/", $bt)) $driver = "tulip";
        if (preg_match("/prism2_tx_timeout/", $bt)) $driver = "hostap";
        if (preg_match("/e100_tx_timeout/", $bt)) $driver = "e100";
        if (preg_match("/e1000_tx_timeout/", $bt)) $driver = "e1000";
        if (preg_match("/e1000e_tx_timeout/", $bt)) $driver = "e1000e";
        if (preg_match("/ns83820_tx_timeout/", $bt)) $driver = "ns83820";
        if (preg_match("/atl1e_tx_timeout/", $bt)) $driver = "atl1e";
        if (preg_match("/atlx_tx_timeout/", $bt)) $driver = "atl1";
        if (preg_match("/atl2_tx_timeout/", $bt)) $driver = "atl2";
        if (preg_match("/tg3_tx_timeout/", $bt)) $driver = "tg3";
        if (preg_match("/sky2_tx_timeout/", $bt)) $driver = "sky2";
        if (preg_match("/sis190_tx_timeout/", $bt)) $driver = "sis190";
        if (preg_match("/ipw2100_tx_timeout/", $bt)) $driver = "ipw2100";
        if (preg_match("/skge_tx_timeout/", $bt)) $driver = "skge";
        if (preg_match("/b44_tx_timeout/", $bt)) $driver = "b44";
        if (preg_match("/el3_tx_timeout/", $bt)) $driver = "3c509";
        if (preg_match("/do_tx_timeout/", $bt)) $driver = "xirc2ps_cs";
        if (preg_match("/Sundance Technology IPG Triple-Speed Ethernet/", $bt)) $driver = "Sundance3";
        if (preg_match("/\[([a-zA-Z0-9\_\-]+)\]/", $bt, $matches) && isset($matches[1])) $driver = $matches[1];
        if (preg_match("/\:([a-zA-Z0-9\_\-]+)\:/", $bt) && isset($matches[1])) $driver = $matches[1];
        if (preg_match("/NETDEV WATCHDOG: .* \(([A-Z0-9a-z\_\-]+)\)/", $bt, $matches) && isset($matches[1])) $driver = $matches[1];
        if (preg_match("/^Component: ([A-Z0-9a-z\_\-\ ]+)$/", $bt, $matches) && isset($matches[1])) $driver = $matches[1];
        if (preg_match("/sis900_tx_timeout/", $bt)) $driver = "sis900";
    }
    return $driver;
}
/* initialize db cache
 * arg1: part of db
 *       "kernel" -> table of kernels
 *       "guilty" -> all guilty tables
 * behavior:
 *      - first init means load db and save into array
 *      - next init call means save changes stored in array and
 *        load data from db into array
 * for finaly save all data into db must call SafeDestroyCache()
*/
function InitCache($section = "") {
    global $debug;
    global $db_cache;
    global $no_older_than;
    if (!isset($no_older_than)) {
        echo "Warning, no_older_than is not set!!!!!!!!\n";
        echo "Setting to default: '2999-12-31\n";
        $no_older_than = new DateTime('2999-12-31');
    }
    if ($section == "kernel") {
        if (isset($db_cache["kernel"])) {
            echo "Warning, kernel cache already initialized!!!!\n";
            return False;
        }
        $db_cache["kernel"] = array();
        $kernels = mysql_query("SELECT id, version FROM kernel");
        if ($kernels) {
            while ($value = mysql_fetch_array($kernels)) {
                $db_cache["kernel"][$value["version"]] = $value["id"];
            }
        } else unset($db_cache["kernel"]);
        unset($kernels);
        uksort($db_cache["kernel"], "kcmp");
    }
    if ($section == "guilty") {
        foreach (array("gmodule", "gfile", "gfunction", "gdriver") as $current_type) {
            if (isset($db_cache[$current_type])) {
                echo "Warning, " . $current_type . " cache already initialized!!!!\n";
                return False;
            }
        }
        foreach (array("gmodule", "gfile", "gfunction", "gdriver") as $current_type) {
            $type = substr($current_type, 1, strlen($current_type) - 1);
            $result = mysql_query("SELECT * FROM guilty_" . $type . " WHERE stamp >='" . $no_older_than->format("Y-m-d") . "'");
            if ($result) {
                while ($value = mysql_fetch_array($result)) {
                    $db_cache[$current_type][$value["distro"]][$value["stamp"]][$value[$type . "ID"]][$value["kernelID"]]["count"] = $value["count"];
                    $db_cache[$current_type][$value["distro"]][$value["stamp"]][$value[$type . "ID"]][$value["kernelID"]]["tcount"] = $value["tcount"];
                    $db_cache[$current_type][$value["distro"]][$value["stamp"]][$value[$type . "ID"]][$value["kernelID"]]["id"] = $value["id"];
                    $db_cache[$current_type][$value["distro"]][$value["stamp"]][$value[$type . "ID"]][$value["kernelID"]]["tainted"] = T_CLEAN;
                }
            } else unset($db_cache[$current_type]);
            unset($result);
        }
        $gkernels = mysql_query("SELECT id, count, tcount, stamp, kernelID, distro FROM guilty_kernel WHERE stamp >='" . $no_older_than->format("Y-m-d") . "'");
        if ($gkernels) {
            while ($value = mysql_fetch_array($gkernels)) {
                $db_cache["gkernel"][$value["distro"]][$value["stamp"]][$value["kernelID"]]["count"] = $value["count"];
                $db_cache["gkernel"][$value["distro"]][$value["stamp"]][$value["kernelID"]]["tcount"] = $value["tcount"];
                $db_cache["gkernel"][$value["distro"]][$value["stamp"]][$value["kernelID"]]["id"] = $value["id"];
                $db_cache["gkernel"][$value["distro"]][$value["stamp"]][$value["kernelID"]]["tainted"] = T_CLEAN;
            }
        } else unset($db_cache["gkernel"]);
        unset($gkernels);
    }
    if ($debug) echo "DB Cache current consume: " . sizeofvar($db_cache) . "!\n";
    return True;
}
/* safe destroy db cache and insert changes into db
 * arg1: part of db
 *      "kernel, module, file function, driver" -> no made any db changes
 *      "guilty" -> made all db changes
*/
function SafeDestroyCache($section = "") {
    global $wpdb;
    global $db_cache;
    if ($section == "") {
        unset($db_cache["kernel"], $db_cache["module"], $db_cache["file"], $db_cache["function"], $db_cache["driver"]);
        SafeDestroyCache("guilty");
    }
    if ($section == "guilty") {
        SafeDestroyCache("gkernel");
        SafeDestroyCache("gmodule");
        SafeDestroyCache("gfile");
        SafeDestroyCache("gfunction");
        SafeDestroyCache("gdriver");
    }
    if ($section == "kernel" || $section == "module" || $section == "file" || $section == "function" || $section == "driver") {
        unset($db_cache[$section]);
        return True;
    }
    // proc guilty kernel
    $sql_i = "";
    unset($sql_u);
    if ($section == "gkernel" && isset($db_cache["gkernel"])) {
        foreach ($db_cache["gkernel"] as $distrokey => $value) {
            foreach ($db_cache["gkernel"][$distrokey] as $stampkey => $value) {
                foreach ($db_cache["gkernel"][$distrokey][$stampkey] as $kernelkey => $value) {
                    if ($db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["tainted"] == T_CLEAN) unset($db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]);
                    else {
                        if ($db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["tainted"] == T_INSERT) {
                            if ($sql_i == "") $sql_i = "INSERT INTO guilty_kernel (kernelID , stamp, count, tcount, distro) VALUES (" . $kernelkey . ", '" . $stampkey . "', " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["count"] . ", " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["tcount"] . ", '" . $distrokey . "')";
                            else $sql_i.= ", (" . $kernelkey . ", '" . $stampkey . "', " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["count"] . ", " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["tcount"] . ", '" . $distrokey . "')";
                        } else {
                            if (!isset($sql_u[0])) {
                                $sql_u[0] = "UPDATE guilty_kernel SET count = CASE id ";
                                $sql_u[1] = "WHEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"] . " THEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["count"] . " ";
                                $sql_u[2] = "END WHERE id IN (" . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"];
                                $sql_u2[0] = "UPDATE guilty_kernel SET tcount = CASE id ";
                                $sql_u2[1] = "WHEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"] . " THEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["tcount"] . " ";
                                $sql_u2[2] = "END WHERE id IN (" . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"];
                            } else {
                                $sql_u[1].= "WHEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"] . " THEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["count"] . " ";
                                $sql_u[2].= ", " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"];
                                $sql_u2[1].= "WHEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"] . " THEN " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["tcount"] . " ";
                                $sql_u2[2].= ", " . $db_cache["gkernel"][$distrokey][$stampkey][$kernelkey]["id"];

                            }
                        }
                    }
                }
            }
        }
        unset($db_cache["gkernel"]);
        if ($sql_i != "") {
            if (!mysql_query($sql_i)) echo "Error(INSERT kernel Guilty): " . mysql_error() . "\n";
        }
        if ($sql_u != "") {
            if (!mysql_query($sql_u[0] . $sql_u[1] . $sql_u[2] . ")")) echo "Error(UPDATE kernel Guilty): " . mysql_error() . "\n";
            if (!mysql_query($sql_u2[0] . $sql_u2[1] . $sql_u2[2] . ")")) echo "Error(UPDATE2 kernel Guilty): " . mysql_error() . "\n";
        }
        $sql_i = "";
        unset($sql_u);
        unset($sql_u2);
    }
    // proc all other guilties
    if ($section == "gmodule" || $section == "gfile" || $section == "gfunction" || $section == "gdriver") {
        $current_type = substr($section, 1, strlen($section) - 1);
        $sql_i = "";
        unset($sql_u);
        if ($db_cache[$section]) {
            foreach ($db_cache[$section] as $distrokey => $value) {
                foreach ($db_cache[$section][$distrokey] as $stampkey => $value) {
                    foreach ($db_cache[$section][$distrokey][$stampkey] as $itemkey => $value) {
                        foreach ($db_cache[$section][$distrokey][$stampkey][$itemkey] as $kernelkey => $value) {
                            if ($db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["tainted"] == T_CLEAN) unset($db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]);
                            else {
                                if ($db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["tainted"] == T_INSERT) {
                                    if ($sql_i == "") $sql_i = "INSERT INTO guilty_" . $current_type . " (" . $current_type . "ID, stamp, kernelID, distro, count, tcount) VALUES (" . $itemkey . ", '" . $stampkey . "', " . $kernelkey . ", '" . $distrokey . "', " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["count"] . ", " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["tcount"] . ")";
                                    else $sql_i.= ", (" . $itemkey . ", '" . $stampkey . "', " . $kernelkey . ", '" . $distrokey . "', " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["count"] . ", " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["tcount"] . ")";
                                } else {
                                    if (!isset($sql_u[0])) {
                                        $sql_u[0] = "UPDATE guilty_" . $current_type . " SET count = CASE id ";
                                        $sql_u[1] = "WHEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"] . " THEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["count"] . " ";
                                        $sql_u[2] = "END WHERE id IN (" . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"];
                                        $sql_u2[0] = "UPDATE guilty_" . $current_type . " SET tcount = CASE id ";
                                        $sql_u2[1] = "WHEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"] . " THEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["tcount"] . " ";
                                        $sql_u2[2] = "END WHERE id IN (" . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"];
                                    } else {
                                        $sql_u[1].= "WHEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"] . " THEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["count"] . " ";
                                        $sql_u[2].= ", " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"];
                                        $sql_u2[1].= "WHEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"] . " THEN " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["tcount"] . " ";
                                        $sql_u2[2].= ", " . $db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]["id"];
                                    }
                                }
                                unset($db_cache[$section][$distrokey][$stampkey][$itemkey][$kernelkey]);
                            }
                        }
                    }
                }
            }
            unset($db_cache[$section]);
            if ($sql_i != "") {
                if (!mysql_query($sql_i)) echo "Error(INSERT " . $section . " Guilty): " . mysql_error() . "\n";
            }
            if ($sql_u != "") {
                if (!mysql_query($sql_u[0] . $sql_u[1] . $sql_u[2] . ")")) echo "Error(UPDATE " . $section . " Guilty): " . mysql_error() . "\n";
                if (!mysql_query($sql_u2[0] . $sql_u2[1] . $sql_u2[2] . ")")) echo "Error(UPDATE2 " . $section . " Guilty): " . mysql_error() . "\n";
            }
            $sql_i = "";
            unset($sql_u);
            unset($sql_u2);
        }
    }
}
/* update stats table in db
 * arg1: parsed oops
 * return: true if update is ok
 * after call UpdateStatsCache() must call destroy cache!!!!!!!!!!
 * this function only update db_cache array, no make any insert!!!!
*/
function UpdateStatsCache($parsed) {
    global $db_cache;
    $kernel_id = $db_cache["kernel"][$parsed["version"]];
    unset($id);
    foreach (array("module", "file", "function", "driver") as $curr) {
        if (isset($parsed["guilty"][$curr]) && $parsed["guilty"][$curr] != "") {
            $id[$curr] = $db_cache[$curr][$parsed["guilty"][$curr]];
        }
    }
    if (isset($db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["count"])) {
		$db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["count"]++;
		if (isset ($parsed["tainted"]) && $parsed["tainted"] != "")
			$db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["tcount"]++;
	}
    else {
        $db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["count"] = 1;
		if (isset ($parsed["tainted"]) && $parsed["tainted"] != "")
			$db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["tcount"] = 1;
		else $db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["tcount"] = 0;
        $db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["id"] = 0;
        $db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["tainted"] = T_INSERT;
    }
    if ($db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["tainted"] == T_CLEAN)
        $db_cache["gkernel"][$parsed["distro"]][$parsed["stamp"]][$kernel_id]["tainted"] = T_UPDATE;

    foreach (array("module", "file", "function", "driver") as $curr) {
        if (isset($id[$curr])) {
            if (isset($db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["count"])) {
                $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["count"]++;
                if (isset ($parsed["tainted"]) && $parsed["tainted"] != "")
                    $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["tcount"]++;
            }
            else {
                $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["count"] = 1;
                if (isset ($parsed["tainted"]) && $parsed["tainted"] != "")
                    $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["tcount"] = 1;
                else $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["tcount"] = 0;
                $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["id"] = 0;
                $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["tainted"] = T_INSERT;
            }
            if ($db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["tainted"] == T_CLEAN) $db_cache["g" . $curr][$parsed["distro"]][$parsed["stamp"]][$id[$curr]][$kernel_id]["tainted"] = T_UPDATE;
        }
    }
}
/* init cache for kffindex
 * kffindex table is too big for direct load to memmory
 * this is reason why load only function witch need
 * arg1: functionID list ( array )
*/
function InitKffCache($fid) {
    if (!$fid) return 0;
    global $kffcache;
    $sql = "";
    foreach ($fid as $key => $value) {
        if (!isset($kffcache[$value])) $sql.= " functionID=" . $value . " OR";
    }
    if ($sql != "") {
        $sql = "SELECT kff.*, f.name from kffindex AS kff, file AS f WHERE (" . $sql;
        $sql = substr($sql, 0, strlen($sql) - 3);
        $sql.= ") AND kff.fileID=f.id";
        $result = mysql_query($sql);
        if ($result) {
            while ($row = mysql_fetch_array($result)) {
                $kffcache[$row["functionID"]][$row["kernelID"]]["file"] = $row["fileID"];
                $kffcache[$row["functionID"]][$row["kernelID"]]["line"] = $row["line"];
                $kffcache[$row["functionID"]][$row["kernelID"]]["fileName"] = $row["name"];
            }
        }
    }
    return 0;
}
/* destroy kffindex cahce */
function DestroyKffCache() {
    global $kffcache;
    unset($kffcache);
    return 0;
}
/*
 * prepare parsed data for insert
 * arg1: parsed oops array
 * function call markup on all functions in backtrace, bugline and guilty
*/
function PrepareInsert($data) {
    global $db_cache, $kffcache, $types, $oopscfg;
    unset($filter);
    foreach ($types as $tp) $filter[$tp] = Array();
    // loop over functions for get names
    foreach ($data as $key => $value) {
        if (isset($data[$key]["cleaned_backtrace"])) {
            foreach ($data[$key]["cleaned_backtrace"] as $tkey => $function) {
                $filter["function"][$function] = - 1;
            }
        }
        if (isset($data[$key]["ip"]["fname"]) && isset($db_cache["kernel"][$data[$key]["version"]])) $filter["function"][$data[$key]["ip"]["fname"]] = - 1;
        foreach ($types as $tp) {
            if (isset($data[$key]["guilty"][$tp]) && $data[$key]["guilty"][$tp] != "") $filter[$tp][$data[$key]["guilty"][$tp]] = - 1;
        }
    }
    // Load data in cache
    $fidlist = Array();
    foreach ($filter as $filter_item => $filter_content) {
        $filter_sql = "";
        foreach ($filter_content as $k => $v) {
            $filter_sql.= " name='" . $k . "' OR";
        }
        if ($filter_sql != "") {
            $filter_sql = substr($filter_sql, 0, strlen($filter_sql) - 3);
            $f = mysql_query("SELECT id, name FROM " . $filter_item . " WHERE " . $filter_sql);
            if ($f) {
                while ($row = mysql_fetch_array($f)) {
                    $db_cache[$filter_item][$row["name"]] = $row["id"];
                    if ($filter_item == "function") $fidlist[] = $row["id"];
                    unset($filter[$filter_item][$row["name"]]);
                }
            }
        }
    }
    // insert new items
    foreach ($filter as $filter_item => $filter_content) {
        $filter_sql = "";
        foreach ($filter_content as $k => $v) {
            $filter_sql.= " ('" . mysql_real_escape_string($k) . "'), ";
        }
        if ($filter_sql != "") {
            $filter_sql = substr($filter_sql, 0, strlen($filter_sql) - 2);
            if (!mysql_query("INSERT INTO " . $filter_item . " (name) VALUES " . $filter_sql)) echo "Warning, INSERT: " . $filter_sql . "\n" . mysql_error();
        }
    }
    // Reload cahce data
    foreach ($filter as $filter_item => $filter_content) {
        $filter_sql = "";
        foreach ($filter_content as $k => $v) {
            $filter_sql.= " name='" . $k . "' OR";
        }
        if ($filter_sql != "") {
            $filter_sql = substr($filter_sql, 0, strlen($filter_sql) - 3);
            $f = mysql_query("SELECT id, name FROM " . $filter_item . " WHERE " . $filter_sql);
            if ($f) {
                while ($row = mysql_fetch_array($f)) {
                    $db_cache[$filter_item][$row["name"]] = $row["id"];
                    if ($filter_item == "function") $fidlist[] = $row["id"];
                    unset($filter[$filter_item][$row["name"]]);
                }
            }
        }
    }
    unset($filter);
    InitKffCache($fidlist);
    // markup backtraces
    foreach ($data as $key => $value) {
        if (isset($data[$key]["cleaned_backtrace"])) {
            unset($out);
            foreach ($data[$key]["cleaned_backtrace"] as $tkey => $function) {
                if (isset($db_cache["kernel"][$data[$key]["version"]])) {
                    $temp = MarkupFunction($data[$key]["version"], $data[$key]["arch"], $function, $data[$key]["oopstype"]);
                    if (strlen($temp) > 0) $out[] = $temp;
                    $temp = "";
                }
            }
            $data[$key]["backtrace"] = AddHtmlTags($out, "array");
        }
        $data[$key]["kernelID"] = $db_cache["kernel"][$data[$key]["version"]];
        if ($data[$key]["hash"] = CalcUniqHash($data[$key])) {
            $db_cache["rawlist"][$data[$key]["rawid"]] = P_OK;
            $db_cache["hashlist"][$data[$key]["hash"]]["rawid"] = $value["rawid"];
        } else {
            $db_cache["rawlist"][$data[$key]["rawid"]] = P_FAIL;
            unset($data[$key]);
        }
    }
    unset($fidlist);
    $sql_where = "";
    if (is_array($db_cache["hashlist"])) {
        foreach ($db_cache["hashlist"] as $key => $value) {
            $sql_where.= " meta_value='" . $key . "' OR";
        }
    }
    if ($sql_where != "") $sql_where = substr($sql_where, 0, strlen($sql_where) - 3);
    $sql = "SELECT post_id, meta_value FROM wp_postmeta WHERE ";
    $sql.= "meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["hash"] . "' AND (" . $sql_where . ")";
    $arr = "";
    $result = mysql_query($sql);
    if ($result) {
        while ($value = mysql_fetch_array($result)) {
            $db_cache["hash"][$value["meta_value"]]["id"] = $value["post_id"];
            $db_cache["hashlist"][$value["meta_value"]] = $value["post_id"];
            $db_cache["translatelist"][$value["post_id"]] = $value["meta_value"];
            $arr.= $value["post_id"] . ", ";
        }
        if ($arr != "") $arr = substr($arr, 0, -2);
    }
    unset($result);
    $sql = "SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE ";
    $sql .= "(meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"] . "' ";
    $sql .= "OR meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["total-count"] . "' ";
    $sql .= "OR meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["raw-list"] . "')";
    $sql.= " AND post_id IN (" . $arr . ")";
    $result = mysql_query($sql);
    if ($result) {
        while ($value = mysql_fetch_array($result)) {
            if ($value["meta_key"] == $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["raw-list"]) $db_cache["hash"][$db_cache["translatelist"][$value["post_id"]]]["rawlist"] = $value["meta_value"];
            elseif ($value["meta_key"] == $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"]) $db_cache["hash"][$db_cache["translatelist"][$value["post_id"]]]["lastseen"] = $value["meta_value"];
            elseif ($value["meta_key"] == $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["total-count"]) $db_cache["hash"][$db_cache["translatelist"][$value["post_id"]]]["totalraw"] = $value["meta_value"];
        }
    }
    DestroyKffCache();
    return $data;
}
function AddHtmlTags($data, $type, $keepkey = False) {
    $content = "";
    if ($type == "array") {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if ($keepkey) $content.= "<li>" . trim($key) . ": " . trim($value) . "</li>";
                else $content.= "<li>" . trim($value) . "</li>";
            }
            unset($key, $value);
            return mysql_real_escape_string($content);
        }
        return "";
    }
    if ($type == "string") {
        if (sizeof($data) > 0) {
            $data = "<li>" . str_replace("\n", "</li><li>", $data);
            $data = substr($data, 0, strlen($data) - 4);
            return mysql_real_escape_string($data);
        } else return "";
    }
}
function DoInsert($data) {
    global $db_cache, $stats, $oopscfg;
    // prepare cache function names, etc.
    $data = PrepareInsert($data);
    InitCache("guilty");
    // loop over oopses
    foreach ($data as $key => $value) {
        if ($db_cache["rawlist"][$value["rawid"]] == P_OK) {
            // too short bugline
            if (strlen($value["bugline"]) < 16) {
                $db_cache["rawlist"][$value["rawid"]] = P_FAIL;
                $stats["fail"]++;
                unset($data[$key]);
            } else {
                // update last seen and rawid info
                if (!isset($db_cache["hash"][$value["hash"]]["lastseen"]) || $db_cache["hash"][$value["hash"]]["lastseen"] <= $value["stamp"]) {
                    $db_cache["hash"][$value["hash"]]["lastseen"] = $value["stamp"];
                    if (isset($db_cache["hash"][$value["hash"]]["rawlist"]) && $db_cache["hash"][$value["hash"]]["rawlist"] != "") $db_cache["hash"][$value["hash"]]["rawlist"].= ", " . $value["rawid"];
                    else $db_cache["hash"][$value["hash"]]["rawlist"] = $value["rawid"];
                }
                // duplicity, only update stats
                if (isset($db_cache["hash"][$value["hash"]]["id"])) {
                    $db_cache["hash"][$value["hash"]]["totalraw"]++;
                    if (isset($db_cache["hash"][$value["hash"]]["rawlist"]) && $db_cache["hash"][$value["hash"]]["rawlist"] != "") $db_cache["hash"][$value["hash"]]["rawlist"].= ", " . $value["rawid"];
                    else $db_cache["hash"][$value["hash"]]["rawlist"] = $value["rawid"];
                    $stats["dup"]++;
                } else {
                    // new oops, insert
                    $db_cache["hash"][$value["hash"]]["totalraw"] = 1;
                    $db_cache["hash"][$value["hash"]]["id"] = InsertOops($data[$key]);
                    $db_cache["hashlist"][$value["hash"]] = $db_cache["hash"][$value["hash"]]["id"];
                    $stats["insert"]++;
                }
            }
        } else unset($data[$key]); // oops with fail flag
        
    }
    foreach ($data as $key => $value) UpdateStatsCache($value);
    unset($sql_u);
    if (is_array($db_cache["hash"])) {
        foreach ($db_cache["hash"] as $k => $v) {
            if (isset($db_cache["hash"][$k]["id"]) && $db_cache["hash"][$k]["id"] > 0) {
                $db_cache["hash"][$k]["rawlist"] = implode(", ", array_unique(explode(",", str_replace(" ", "", $db_cache["hash"][$k]["rawlist"]))));
                if (!isset($sql_u[0])) {
                    $sql_u[0] = "UPDATE wp_postmeta SET meta_value = CASE post_id WHEN " . $db_cache["hash"][$k]["id"] . " THEN '" . $db_cache["hash"][$k]["lastseen"] . "' ";
                    $sql_u[1] = "UPDATE wp_postmeta SET meta_value = CASE post_id WHEN " . $db_cache["hash"][$k]["id"] . " THEN '" . $db_cache["hash"][$k]["totalraw"] . "' ";
                    $sql_u[2] = "UPDATE wp_postmeta SET meta_value = CASE post_id WHEN " . $db_cache["hash"][$k]["id"] . " THEN '" . $db_cache["hash"][$k]["rawlist"] . "' ";
                    $sql_u[3] = " END WHERE post_id IN (" . $db_cache["hash"][$k]["id"];
                } else {
                    $sql_u[0].= "WHEN " . $db_cache["hash"][$k]["id"] . " THEN '" . $db_cache["hash"][$k]["lastseen"] . "' ";
                    $sql_u[1].= "WHEN " . $db_cache["hash"][$k]["id"] . " THEN " . $db_cache["hash"][$k]["totalraw"] . " ";
                    $sql_u[2].= "WHEN " . $db_cache["hash"][$k]["id"] . " THEN '" . $db_cache["hash"][$k]["rawlist"] . "' ";
                    $sql_u[3].= ", " . $db_cache["hash"][$k]["id"];
                }
            }
        }
    }
    if (isset($sql_u[0])) {
        if (!mysql_query($sql_u[0] . $sql_u[3] . ") AND meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["last-seen"] . "'")) echo "Warning, last seen update: " . mysql_error();
    }
    if (isset($sql_u[1])) {
        if (!mysql_query($sql_u[1] . $sql_u[3] . ") AND meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["total-count"] . "'")) echo "Warning, raw count update: " . mysql_error();
    }
    if (isset($sql_u[2])) {
        if (!mysql_query($sql_u[2] . $sql_u[3] . ") AND meta_key='" . $oopscfg["wpcf"]["dbprefix"] . $oopscfg["wpcf"]["slug"]["raw-list"] . "'")) echo "Warning, raw list update: " . mysql_error();
    }
    unset($sql_u);
    // follow backtrace cache
    foreach ($data as $key => $value) {
        if ($value["backtrace"] != "") $db_cache["backtrace"][$value["hash"]][] = str_replace("\\\"", "\"", $value["backtrace"]);
    }
    // uniq backtraces
    if (is_array($db_cache["backtrace"])) {
        foreach ($db_cache["backtrace"] as $key => $value) {
            $unique = array_map('unserialize', array_unique(array_map('serialize', $value)));
            unset($db_cache["backtrace"][$key]);
            $db_cache["backtrace"][$key] = $unique;
        }
    }
    foreach ($db_cache["backtrace"] as $key => $value) {
        $post = get_post($db_cache["hash"][$key]["id"]);
        $stored_traces = new WPCF_Repeater();
        $stored_traces->set($post, wpcf_admin_fields_get_field($oopscfg["wpcf"]["slug"]["trace"]));
        $meta = $stored_traces->_get_meta();
        if (is_array($meta["by_meta_id"])) {
            foreach ($meta["by_meta_id"] as $mk => $mv) {
                if (!is_array($mv)) $mv = Array(0 => $mv);
                foreach ($mv as $msk => $msv) {
                    foreach ($value as $sk => $sv) {
                        if (!strcmp("$msv", "$sv")) unset($db_cache["backtrace"][$key][$sk]);
                    }
                }
            }
        }
        if (count($db_cache["backtrace"][$key]) > 0) {
            echo "New trace for " . $key . "\n";
            $stored_traces->save(array_merge($db_cache["backtrace"][$key], $meta["by_meta_id"]));
        }
        unset($stored_traces);
    }
    // update raw record flags
    if (isset($db_cache["rawlist"])) {
        $sql_ok = "UPDATE raw_data SET status = " . P_OK . " WHERE id=0";
        $sql_fail = "UPDATE raw_data SET status = " . P_FAIL . " WHERE id=0";
        foreach ($db_cache["rawlist"] as $key => $value) if ($value == P_OK) $sql_ok.= " OR id=" . (int)$key;
        else $sql_fail.= " OR id=" . (int)$key;
        unset($db_cache["rawlist"]);
        if (!mysql_query($sql_ok)) echo "UPDATE Error(P_OK): " . mysql_error() . "\n";
        if (!mysql_query($sql_fail)) echo "UPDATE Error(P_FAIL): " . mysql_error() . "\n";
        $sql_ok = $sql_fail = "";
    }
    SafeDestroyCache();
}
/* insert parsed oops into DB
 * arg1: parsed oops
 * return: New post_ID
*/
function InsertOops($parsed) {
    global $oopscfg, $db_cache, $wpdb, $wpcf;
    $newoops = array('post_title' => $parsed["bugline"],
                     'post_name' => str_replace(array("/"), array("-"), $parsed["bugline"]),
                     'post_content' => '',
                     'post_type' => 'oops',
                     'post_status' => 'publish',
                     'comment_status' => 'open'
                     );
    $pid = wp_insert_post($newoops);
    wp_set_object_terms($pid, $parsed["version"], "kernel");
    $distro = explode(" ", $parsed["distro"]);
    wp_set_object_terms($pid, ucfirst(trim($distro[0])), "distro");
    $guilty = "";
    foreach (Array("module", "file", "function", "driver") as $type) {
        if (!isset($parsed["guilty"][$type]) || trim($parsed["guilty"][$type]) == "") $parsed["guilty"][$type] = "";
        else $guilty.= "<li>" . ucfirst($type) . ": " . $parsed["guilty"][$type] . "</li>";
    }
    if (isset($parsed["ip"]["ipaddr"])) $ip = $parsed["ip"]["ipaddr"];
    else $ip = "";
    $modlist = "";
    if (is_array($parsed["modules"])) {
        foreach ($parsed["modules"] as $modname) $modlist.= $modname . ", ";
        $modlist = substr($modlist, 0, -2);
    }
    $pmodlist = "";
    if (is_array($parsed["backtrace_modules"])) {
        foreach ($parsed["backtrace_modules"] as $modname) $pmodlist.= $modname . ", ";
        $pmodlist = substr($pmodlist, 0, -2);
    }
    if ($parsed["dissasm"] != "") {
        $temp = explode("\n", trim($parsed["dissasm"]));
        $parsed["dissasm"] = AddHtmlTags($temp, "array");
    }
    $post = get_post($pid);
    $hw = "";
    if ($parsed["hwname1"] == $parsed["hwname2"]) $parsed["hwname2"] = "";
    if ($parsed["hwname1"] == "Unknown") $parsed["hwname1"] = "";
    if ($parsed["hwname2"] == "Unknown") $parsed["hwname2"] = "";
    if ($parsed["hwname1"] != "" && $parsed["hwname2"] != "") $hw = $parsed["hwname1"] . ", " . $parsed["hwname2"];
    elseif ($parsed["hwname1"] == "" && $parsed["hwname2"] == "") $hw = "Unknown";
    else $hw = $parsed["hwname1"] . $parsed["hwname2"];
    $ins = Array($oopscfg["wpcf"]["slug"]["type"] => $parsed["oopstype"],
                 $oopscfg["wpcf"]["slug"]["class"] => $parsed["class"],
                 $oopscfg["wpcf"]["slug"]["kernel"] => $parsed["version"],
                 $oopscfg["wpcf"]["slug"]["tainted"] => TranslateTainted($parsed["tainted"]),
                 $oopscfg["wpcf"]["slug"]["distro"] => $parsed["distro"],
                 $oopscfg["wpcf"]["slug"]["architecture"] => $parsed["arch"],
                 $oopscfg["wpcf"]["slug"]["hardware"] => $hw,
                 $oopscfg["wpcf"]["slug"]["last-sys-file"] => $parsed["lastsysfs"],
                 $oopscfg["wpcf"]["slug"]["caused-by"] => $guilty,
                 $oopscfg["wpcf"]["slug"]["guilty-link"] => $parsed["guiltylink"],
                 $oopscfg["wpcf"]["slug"]["ip"] => $ip,
                 $oopscfg["wpcf"]["slug"]["registers"] => $parsed["registers"],
                 $oopscfg["wpcf"]["slug"]["stack"] => $parsed["stack"],
                 $oopscfg["wpcf"]["slug"]["disassm"] => $parsed["dissasm"],
                 $oopscfg["wpcf"]["slug"]["trace"] => $parsed["backtrace"],
                 $oopscfg["wpcf"]["slug"]["modules-participated"] => $pmodlist,
                 $oopscfg["wpcf"]["slug"]["linked-modules"] => $modlist,
                 $oopscfg["wpcf"]["slug"]["total-count"] => 1,
                 $oopscfg["wpcf"]["slug"]["last-seen"] => $parsed["stamp"],
                 $oopscfg["wpcf"]["slug"]["hash"] => $parsed["hash"],
                 $oopscfg["wpcf"]["slug"]["raw-list"] => $parsed["rawid"],
                 $oopscfg["wpcf"]["slug"]["kernel-sort"] => ksrt($parsed["version"]),
                 $oopscfg["wpcf"]["slug"]["bugline"] => $parsed["bugline"]
                 );
    foreach ($ins as $key => $value) if ($ins[$key] == "") unset($ins[$key]);
    $groups = wpcf_admin_post_get_post_groups_fields($post);
    if (empty($groups)) {
        return false;
    }
    $all_fields = array();
    $_not_valid = array();
    $_error = false;
    foreach ($groups as $group) {
        if (isset($group['fields'])) {
            $fields = wpcf_admin_post_process_fields($post, $group['fields'], true, false, 'validation');
            $form = wpcf_form_simple_validate($fields);
            $all_fields = $all_fields + $fields;
            if ($form->isError()) {
                $_error = true;
                $_not_valid = array_merge($_not_valid, (array)$form->get_not_valid());
            }
        }
    }
    foreach ($all_fields as $k => $v) {
        if (empty($v['wpcf-id'])) {
            continue;
        }
        $_temp = new WPCF_Field();
        $_temp->set($wpcf->post, $v['wpcf-id']);
        $all_fields[$k]['_field'] = $_temp;
    }
    foreach ($_not_valid as $k => $v) {
        if (empty($v['wpcf-id'])) {
            continue;
        }
        $_temp = new WPCF_Field();
        $_temp->set($wpcf->post, $v['wpcf-id']);
        $_not_valid[$k]['_field'] = $_temp;
    }
    $error = apply_filters('wpcf_post_form_error', $_error, $_not_valid, $all_fields);
    $not_valid = apply_filters('wpcf_post_form_not_valid', $_not_valid, $_error, $all_fields);
    if ($error) {
        echo "Error in input data\n";
    }
    if (!empty($not_valid)) {
        update_post_meta($post->ID, 'wpcf-invalid-fields', $not_valid);
    }
    if (!empty($ins)) {
        foreach ($ins as $field_slug => $field_value) {
            $field = wpcf_fields_get_field_by_slug($field_slug);
            if (empty($field)) {
                continue;
            }
            $wpcf->field->set($post->ID, $field);
            if (isset($parsed['wpcf_repetitive_copy'][$field['slug']])) {
                continue;
            }
            if (isset($not_valid[$field['slug']])) {
                continue;
            }
            if (isset($parsed['__wpcf_repetitive'][$wpcf->field->slug])) {
                $wpcf->repeater->set($post->ID, $field);
                $wpcf->repeater->save($field_value);
            } else {
                $wpcf->field->save($field_value);
            }
            do_action('wpcf_post_field_saved', $post->ID, $field);
        }
    }
    do_action('wpcf_post_saved', $post->ID);
    unset($ins);
    echo "Hash: " . $parsed["hash"] . ", Raw: " . $parsed["rawid"] . " Insert OK\n";
    return $pid;
}
/*
 * some gcc versions put full pathnames in BUG lines;
 * we only need the path relative to start of root, while
 * people could genuinly worry about privacy data given
 * that usernames may show up in this.. this function
 * tries to chop unneed stuff out
*/
function Anonymize_bugline($func) {
    $fix[0] = strpos($func, "/");
    $fixarr = array("arch/",
                    "block/",
                    "crypto/",
                    "Documentation/",
                    "drivers/",
                    "firmware/",
                    "fs/",
                    "include/",
                    "init/",
                    "ipc/",
                    "kernel/",
                    "lib/",
                    "mm/",
                    "net/",
                    "samples/",
                    "scripts/",
                    "security/",
                    "sound/",
                    "tags/",
                    "tools/",
                    "usr/",
                    "virt/"
                    );
    foreach ($fixarr as $key => $value) {
        if (($fix[1] = strpos($func, $value)) !== false) break;
    }
    if ($fix[1] > $fix[0]) $func = substr($func, 0, $fix[0]) . substr($func, $fix[1]);
    while ($func[strlen($func) - 1] == ")") $func = trim(substr($func, 0, strrpos($func, "(")));
    return $func;
}
/* obfuscate private data in raw oops
 * arg1: raw oops
 * return: obfuscated raw
*/
function ObfuscateRaw($oops) {
    $obf_oops = "";
    $lines = preg_split("/[\n]/", $oops);
    foreach ($lines as $key => $value) {
        $value = Anonymize_bugline($value);
        $obf_oops.= $value . "\n";
    }
    return $obf_oops;
}
/* natural compare kernel versions ( helper for user sorting )
 * arg1: kernel version string
 * arg2: kernel version string
 * return: if(arg1 < arg2) -1
 *         if(arg1 == arg2) 0
 *         if(arg1 > arg2) 1
*/
function kcmp($a, $b) {
    $aint = ksrt($a);
    $bint = ksrt($b);
    if($aint < $bint) return -1;
    if($aint > $bint) return 1;
    return 0;
}
/* create sorted number from kernel version string
 * arg1: kernel version string
 * return: sortable int
*/
function ksrt($version) {
    $ctrl = 0;
    $retstr = "";
    $version = str_replace("-", ".", $version);
    $kernel = explode(".", $version);
    for($i=count($kernel);$i<6;$i++)
        $kernel[$i] = 0;
    while ($ctrl < 6) {
        if (isset($kernel[$ctrl])) {
            if (strpos($kernel[$ctrl], "r") !== False) {
                $kernel[$ctrl] = str_replace("rc", "", $kernel[$ctrl]);
                $kernel[$ctrl] = str_replace("pre", "", $kernel[$ctrl]);
                $retstr.= str_pad($kernel[$ctrl], 3, "0", STR_PAD_LEFT);
            } else {
                $retstr.= (int)$kernel[$ctrl] + 100;
            }
        } else {
            $retstr.= "000";
        }
        $ctrl++;
    }
    return $retstr;
}
/* get filtered wp_post ids
 * arg1: array of filter conditions
 * conds = Array(relation => "","key" => "","value" => "","compare" => "","type" => "")
 * return: array of filtered post ids
*/
function getFilteredList($filter) {
    $currentList = null;
    foreach ( $filter as $value) {
        if (!isset($value["key"])) {
            $sql = "SELECT post_id FROM wp_postmeta WHERE (";
            foreach ($value as $subval) {
                $sql .= "(meta_key='" . $subval["key"] . "' AND ";
                $sql .= "meta_value " . $subval["compare"];
                if ($subval["type"] == "string")
                    $sql .= " '" . $subval["value"] . "'"; 
                else $sql .= $subval["value"];
                $sql .= ") OR ";
            }
            if (substr($sql, strlen($sql)-3,2) == "OR")
                $sql = substr($sql, 0, -4) . ")";
        } else {
            $sql = "SELECT post_id FROM wp_postmeta WHERE ";
            $sql .= "meta_key='" . $value["key"] . "' AND ";
            $sql .= "meta_value " . $value["compare"];
            if ($value["type"] == "string")
                $sql .= " '" . $value["value"] . "'"; 
            else $sql .= $value["value"];
        }
        if (is_array($currentList) && count($currentList) == 0)
            return $currentList;
        if (is_array($currentList) && count($currentList) > 0)
            $sql .= " AND post_id IN (" . implode("," , $currentList) . ")";
        unset ($currentList);
        $currentList = Array();
        $qry = mysql_query($sql);
        if (!$qry) {
            echo mysql_error();
            return $currentList;
        }
        while ($row = mysql_fetch_array($qry))
            $currentList[] = $row["post_id"];
    }
    return $currentList;
}
/* statistic functions */
function stat_standard_deviation($aValues, $bSample = false) {
    $fMean = array_sum($aValues) / count($aValues);
    $fVariance = 0.0;
    foreach ($aValues as $i) {
        $fVariance+= pow($i - $fMean, 2);
    }
    $fVariance/= ($bSample ? count($aValues) - 1 : count($aValues));
    return (float)sqrt($fVariance);
}
function stat_relative_jmp_up($values) {
    $max = 0;
    $last = $values[key($values) ];
    foreach ($values as $i) {
        $max = (($i / $last) > $max) ? ($i / $last) : $max;
        $last = $i;
    }
    return $max;
}
function stat_absolute_jmp_up($values) {
    $max = 0;
    $last = $values[key($values) ];
    foreach ($values as $i) {
        $max = (($i - $last) > $max) ? ($i - $last) : $max;
        $last = $i;
    }
    return $max;
}
function stat_sum($values) {
    $max = 0;
    foreach ($values as $i) {
        $max+= $i;
    }
    return $max;
}
function stat_last($values) {
    $max = array_slice($values, -1);
    return $max[key($max) ];
}
function stat_top($values) {
    $max = 0;
    foreach ($values as $i) $max = $max < $i ? $i : $max;
    return $max;
}
?>
