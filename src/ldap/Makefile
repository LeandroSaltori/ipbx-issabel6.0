INSTALL = install
CHKCONFIG = /sbin/chkconfig
prefix  ?= /usr/local
SHELL := /bin/bash
MYSQL = mysql
MAKEFLAGS += --no-print-directory

PROGRAMS := binary

THIS_FILE := $(lastword $(MAKEFILE_LIST))

log_success = @(echo -e "\x1B[32m>> $1\x1B[39m")
log_error = @(>&2 echo -e "\x1B[31m>> $1\x1B[39m" && exit 1)

$(phony all): $(PROGRAMS)

.PHONY install: binary init restart message
.PHONY upgrade: binary init restart message

binary:
	@test -d $(prefix) || mkdir -p $(prefix); \
	echo -e "\x1B[32m>> Installing daemon\x1B[39m"; \
	$(INSTALL) -m 755 issabel-ldap /usr/local/bin/;

init:
	@$(INSTALL) -m 644 systemd/issabel-ldap.service /etc/systemd/system/issabel-ldap.service >/dev/null; \
	systemctl daemon-reload 1>&2 2> /dev/null; \
	systemctl enable issabel-ldap.service 1>&2 2> /dev/null; \
	if [ ! -f /etc/sysconfig/issabel-ldap ]; then \
		$(INSTALL) -m 640 systemd/issabel-ldap.sysconfig /etc/sysconfig/issabel-ldap 1>&2 2> /dev/null; \
		$(MAKE) -f $(THIS_FILE) setpassword;\
	fi; \

uninstall:
	@systemctl stop issabel-ldap.service 1>&2 2> /dev/null; \
	systemctl disable issabel-ldap.service 1>&2 2> /dev/null; \
	systemctl daemon-reload 1>&2 2> /dev/null; \
	rm -rf /etc/systemd/system/issabel-ldap.service; \
	rm -rf /etc/sysconfig/issabel-ldap; \
	rm -rf /usr/local/bin/issabel-ldap; 
	@echo -e "\x1B[31m>> Uninstalled!\x1B[39m"

setpassword:
	@stty echonl; \
	admin_pw=1; \
	admin_pw_confirm=2; \
	rm -f /tmp/mpwd; \
	x=1;\
	while [ "$$admin_pw" != "$$admin_pw_confirm" ] ;\
	do \
	if [ $$x -gt 1 ]; then \
		echo -e "\x1B[31m>> Password do not match!\x1B[39m"; \
	fi; \
	x=$$(( $$x + 1 )); \
	unset admin_pw; \
	echo ; \
	echo -n 'Enter a password for the LDAP Server: ' 1>&2 ;\
	while true; do \
	IFS= read -r -N1 -s char; \
	code=$$(printf '%02x' "'$$char"); \
	case "$$code" in \
	''|0a|0d) break ;; \
	08|7f) \
	if [ -n "$$admin_pw" ]; then \
			admin_pw="$$( echo "$$admin_pw" | sed 's/.$$//' )"; \
			echo -n $$'\b \b' 1>&2; \
	  	fi\
	  	;;\
	15)\
	  	echo -n "$$admin_pw" | sed 's/./\cH \cH/g' >&2;\
	  	admin_pw=''; \
	  	;;\
	[01]?) ;;\
	*)  admin_pw="$$admin_pw$$char"; \
	  	echo -n '*' 1>&2; \
	  	;; \
	esac; \
	done; \
	echo ;\
	unset admin_pw_confirm; \
	echo -n 'Confirm the password for the LDAP Server: ' 1>&2 ;\
	while true; do \
	IFS= read -r -N1 -s char; \
	code=$$(printf '%02x' "'$$char"); \
	case "$$code" in \
	''|0a|0d) break ;; \
	08|7f) \
	if [ -n "$$admin_pw_confirm" ]; then \
			admin_pw_confirm="$$( echo "$$admin_pw_confirm" | sed 's/.$$//' )"; \
			echo -n $$'\b \b' 1>&2; \
	  	fi\
	  	;;\
	15)\
	  	echo -n "$$admin_pw_confirm" | sed 's/./\cH \cH/g' >&2;\
	  	admin_pw_confirm=''; \
	  	;;\
	[01]?) ;;\
	*)  admin_pw_confirm="$$admin_pw_confirm$$char"; \
	  	echo -n '*' 1>&2; \
	  	;; \
	esac; \
	done; \
	echo ;\
	done ;\
	escaped_pwd=$$(echo $$admin_pw | sed 's/\"/\\\"/'); \
	echo ;\
	echo "OPTIONS=\"-ldappass $$escaped_pwd\"" >/etc/sysconfig/issabel-ldap;
	@echo -e "\x1B[32m>> Password set!\x1B[39m"; \
	echo 

restart:
	@systemctl restart issabel-ldap.service 1>&2 2> /dev/null; \
	if [ $$? -eq 0 ]; then \
		echo -e "\x1B[32m>> Service restarted!\x1B[39m"; \
	else \
		echo -e "\x1B[31m>> Problem restarting service!\x1B[39m"; \
	fi; \
	echo

message:
	@echo -e "\033[0;36mLDAP is now started and listening on port \033[1;33mTCP/10389\033[0;36m. You can log"; \
	echo -e "with the user \033[1;33mcn=admin,dc=pbx,dc=com\033[0;36m and the password you've just set."; \
	echo ;\
	echo "Remember to configure your firewall to allow connections to that port."; \
	echo "Phone configuration samples are found on the README.md file"; \
	echo -e "\033[0m"

