#!/bin/bash
if [ -d "linux-stable" ]; then
	cd linux-stable
	git fetch
else
	echo "Directory not found -> creating"
	git clone git://git.kernel.org/pub/scm/linux/kernel/git/stable/linux-stable.git
	cd linux-stable
fi

# loop over tags
for current in $( git tag )
do
	# skip bad commits ( bad tag )
	if [ $current = "help" ] || [ $current = "latest" ] || 
	[ $current = "v2.6.11" ] || [ $current = "v2.6.11-tree" ] ;
		then continue; fi
	# skip all files, if parsed file already exist
	if [ -f "../tags/${current:1}" ]; then continue; fi
	# checkout last unparsed tag
	git checkout $current
	echo "Parsing: ${current:1}"
	#ctags -R -x --c-kinds=f ./ | grep function | sed -e "s/^\([^ ]*\)[ ]*function[ ]*\([0-9]*\)[ ]*\([^ ]*\)[ ]*\(.*\)/\3:\2:\1/" | grep "^./" | sort -f | uniq > "../tags/${current:1}"
	find . -type f -iname "*.[cChH]" | xargs ../ctags -x --c-kinds=f | grep -e"function" -e"inline" | awk '{print $4":"$3":"$1}' | sort -f | uniq > "../tags/${current:1}"
done
git checkout origin/master --force

