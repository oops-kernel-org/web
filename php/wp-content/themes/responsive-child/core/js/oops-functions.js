function clrclass() {
	var element = document.getElementById('oopsclass');
	element.value = 'default';
}

function clrtype() {
	var element = document.getElementById('oopstype');
	element.value = 'default';
}

function showFilter() {
	var display = document.getElementById('filterArea').style.display;

	if (display == "block") {
		document.getElementById('filterAreaControl').innerHTML = "Show Filter";
		document.getElementById('filterArea').style.display = "none";
	}
	else {
		document.getElementById('filterAreaControl').innerHTML = "Hide Filter";
		document.getElementById('filterArea').style.display = "block";
	}

	return false;
}

function showRaw(id,token) {
	jQuery('#raw').load('http://oops.kernel.org/get-raw.php?id='+id+'&token='+token);
	return false;
}

