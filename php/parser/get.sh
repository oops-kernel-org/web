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
	../ctags -R -x --c-kinds=f ./ | grep function | sed -e "s/^\([^ ]*\)[ ]*function[ ]*\([0-9]*\)[ ]*\([^ ]*\)[ ]*\(.*\)/\3:\2:\1/" | grep "^./" | sort -f | uniq > "../tags/${current:1}"
	#for file in $( tree -inf | grep ".*\.c$" )
	#do
		#cat $file | sed -e "s/\/\/.*//g" -e ':1;N;s/\(([^)]*\)[\n]/\1/g;b1' -e 's/\\/*.*\*\//g' -e "s/^[ \t]*//;s/[ \t]*$//;s/[ \t]\{1,\}/ /g" \
		#-e 's/[ ],/,/g' -e 's/,\([^ ]\)/, \1/g' -e 's/\([^ \/]\)\*/\1 \*/g' -e 's/\*[ ]/\*/g' > $file"-temp"
	#	mv $file"-temp" $file
	#	ctags -x --c-kinds=f $file | grep function | sed -e "s/\([^ ]*\).*\(\.\/.*\.c\).*\((.*)\)/\2:\1\3/g" >> "tags/kernel-$current"
	#done
	#exit
done
git checkout origin/master
