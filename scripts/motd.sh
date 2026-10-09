#!/bin/sh

# Check for existence of /etc/init.d/wanrouter
if [ ! -e /etc/init.d/wanrouter ] ; then
        if [ -e /usr/sbin/wanrouter ] ; then
                ln -s /usr/sbin/wanrouter /etc/init.d/wanrouter
		service asterisk stop > /dev/null 2>&1
		service dahdi stop > /dev/null 2>&1
		service wanrouter stop > /dev/null 2>&1
		service wanrouter start > /dev/null 2>&1
		service dahdi start > /dev/null 2>&1
		service asterisk start > /dev/null 2>&1
        fi
fi

MSJ_NO_IP_DHCP="If you could not get a DHCP IP address please type setup and select \"Network configuration\" to set up a static IP."
INTFCNET=`ls -A /sys/class/net/`


echo ""
echo -e " _ __  _ __(_)___ _ __ ___   ___       "
echo -e "| '_ \| '__| / __| '_ \` _ \ / _ \     "
echo -e "| |_) | |  | \__ \ | | | | | (_| |     "
echo -e "| .__/|_|  |_|___/_| |_| |_|\__,_|     "
echo -e "|_|                                    "
echo ""
echo "Para acessar seu sistema IPbx Prisma Telecom, usando um computador (PC/MAC/Linux)"
echo "Abra o navegador de internet e digite o seguinte endereco: "
echo -e "\e[4m"

cont=0
for x in $INTFCNET
do
	case $x in
		lo*)
		;;

		sit*)
		;;
				
		# Since CentOS 7 the way of naming network interfaces change to "Consistent Network Device Naming"
		# wich implements a change in the usual name 'ethN' to others network names of the form:
		# en* for ethernet interfaces
		# wl* for wireless lan interfaces
		# ww* for wireless wan interfaces
		# sl* for lineal serial interfaces
		eth*|en*|ww*|wl*|sl*)
			IPADDR[$cont]=$(ip a s $x | awk -F"[/ ]+" '/inet / {print $3}')
		;;
	esac
	let "cont++"
done
if [ "$IPADDR[@]" = "" ]; then
   echo "https://<YOUR-IP(s)-HERE>"
   echo "$MSJ_NO_IP_DHCP"
else
   arr=$(echo ${IPADDR[@]} | tr " " "\n")
   for IPs in $arr
   do
	  echo "https://$IPs"
   done
fi

echo -e "\e[0m"
echo -e "\e[1mAcesse o site e conheca nossos produtos e servicos: \e[4mhttp://www.prismatelecom.com/\e[0m"
echo -e " "
