# Issabel LDAP Directory
A simple LDAP server to serve Issabel address book and extensions contacts

## How it works
It starts the LDAP service on port 10389 and responds to directory search 
requests by translating them into a SQL query against IssabelPBX users table
and Issabel SQLite Address book

If a ldap password is supplied in startup parameters, then ldap queries will
be authenticated with user: cn=admin,dc=pbx,dc=com and the password supplied
in the command line -ldappass parameter

If no ldap password is supplied, then the server will allow anonymous/guest
queries with no authentication

Two fields are returned for each result are:

* "displayName"
* "cn" (common name or full name)
* "gn" (given name)
* "sn" (surname)
* "telephoneNumber"
* "mobile"
* "homePhone" (could be the extension number)
* "mail"

## How to Install

On the command line run:

```
make install
```

## How to Uninstall

On the command line run:

```
make uninstall
```

## Issabel Firewall Configuration

You must define a new PORT in Issabel Firewall with port TCP 10389 and then
add a rule allowing traffic on that port from your phone network.
 
## Startup parameters

-ldappass  : optional password for authenticated LDAP queries
-ldapuser  : user for authenticated LDAP queries. Default value:  cn=admin,dc=pbx,dc=com

If no ldap password is supplied on the command line, the server will allow anonymous
unathenticated connections. You will have to edit the file /etc/sysconfig/issabel-ldap 
and set the parameters you wish inside the OPTIONS variable. Asuming you would like to have
the user "myuser" with password "mypassword", that file should look like this

```
OPTIONS="-ldapuser myuser -ldappass mypassword"
```

## Phone Configuration

You'll need to configure your IP phones to look up against the LDAP server.

See examples below:

### Snom720 - snom720-main.htm
```xml
<?xml version="1.0" encoding="utf-8"?>
<settings>
        <phone-settings>
                *** Other Settings ***

                <ldap_server perm="">***server_ip***</ldap_server>
                <ldap_port perm="">10389</ldap_port>
                <ldap_base perm="">dc=asterisk</ldap_base>
                <ldap_username perm="">asterisk</ldap_username>
                <ldap_max_hits perm="">100</ldap_max_hits>
                <ldap_search_filter perm="">(&(telephoneNumber=*)(cn=%))</ldap_search_filter>
                <ldap_number_filter perm="">(&(telephoneNumber=%)(cn=*))</ldap_number_filter>
                <ldap_name_attributes perm="">cn</ldap_name_attributes>
                <ldap_number_attributes perm="">telephoneNumber</ldap_number_attributes>
                <ldap_display_name perm="">%cn</ldap_display_name>

                <gui_fkey1 perm="">keyevent F_DIRECTORY_SEARCH</gui_fkey1>
        </phone-settings>
</settings>
```

### Snom300 - snom300-main.htm
```xml
<?xml version="1.0" encoding="utf-8"?>
<settings>
        <phone-settings>
                *** Other Settings ***

                <ldap_server perm="">***server_ip***</ldap_server>
                <ldap_port perm="">10389</ldap_port>
                <ldap_base perm="">dc=asterisk</ldap_base>
                <ldap_username perm="">asterisk</ldap_username>
                <ldap_max_hits perm="">25</ldap_max_hits>
                <ldap_search_filter perm="">(&(telephoneNumber=*)(cn=%))</ldap_search_filter>
                <ldap_number_filter perm="">(&(telephoneNumber=%)(cn=*))</ldap_number_filter>
                <ldap_name_attributes perm="">cn</ldap_name_attributes>
                <ldap_number_attributes perm="">telephoneNumber</ldap_number_attributes>
                <ldap_display_name perm="">%cn</ldap_display_name>

                <idle_cancel_key_action perm="">keyevent F_DIRECTORY_SEARCH</idle_cancel_key_action>
        </phone-settings>
        <functionKeys e="2">
                <fkey idx="3" context="active" label="" perm="">keyevent F_DIRECTORY_SEARCH</fkey>
        </functionKeys>
</settings>
```

### Polycom SoundPoint IP - sip.cfg (must be firmware UC 4+)
```xml
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<localcfg>
  *** Other Settings ***

  <dir>
      <dir.corp
          dir.corp.address="ldap://***server_ip***"
          dir.corp.port="10389"
          dir.corp.transport="TCP"
          dir.corp.baseDN="dc=asterisk"
          dir.corp.scope="sub"
          dir.corp.filterPrefix=""
          dir.corp.user="cn=admin,dc=pbx,dc=com"
          dir.corp.pageSize="32"
          dir.corp.password="password"
          dir.corp.cacheSize="128"
          dir.corp.leg.pageSize="8"
          dir.corp.leg.cacheSize="32"
          dir.corp.autoQuerySubmitTimeout="1"
          dir.corp.viewPersistence="0"
          dir.corp.leg.viewPersistence="0"
          dir.corp.sortControl="0">
          <dir.corp.attribute
              dir.corp.attribute.1.name="dn"
              dir.corp.attribute.1.label="Display Name"
              dir.corp.attribute.1.type="first_name"
              dir.corp.attribute.1.searchable="1"
              dir.corp.attribute.1.filter=""
              dir.corp.attribute.1.sticky="0"
              dir.corp.attribute.2.name="telephoneNumber"
              dir.corp.attribute.2.label="phone number"
              dir.corp.attribute.2.type="phone_number"
              dir.corp.attribute.2.filter=""
              dir.corp.attribute.2.sticky="0"
              dir.corp.attribute.2.searchable="1">
          </dir.corp.attribute>
          <dir.corp.backGroundSync
              dir.corp.backGroundSync.period="3600">
          </dir.corp.backGroundSync>
          <dir.corp.vlv
              dir.corp.vlv.allow="1"
              dir.corp.vlv.sortOrder="dn telephoneNumber">
          </dir.corp.vlv>
      </dir.corp>
  </dir>

  <feature feature.corporateDirectory.enabled="1"/>
  <softkey softkey.feature.directories="1"/>
</localcfg>

### YeaLink Phones

Log in the Yealink phone web interface, go to “Directory > LDAP”, Select Enabled from the pull-down list of Enable LDAP.
Enter the desired values in the corresponding fields.
Enable “LDAP Lookup For Incoming Call”.
Enable “LDAP Lookup For Callout”.
Enable “LDAP Sorting Result”.
Click “Confirm” to accept the change.

```
Enable LDAP: Enabled
LDAP Name Filter: (|(cn=%)(sn=%))
LDAP Number Filter: (|(telephoneNumber=%)(homePhone=%))
Server Address: 192.168.6.216  
Port: 10389
Base: dc=pbx,dc=com
UserName: cn=admin,dc=pbx,dc=com
Password:  password
Max.Hits(1~32000): 50
LDAP Name Attributes: cn sn gn
LDAP Number Attributes: telephoneNumber homePhone
LDAP Display Name: %cn
Protocol: Version 3
LDAP Lookup For Incoming Call: Enabled
LDAP Lookup For Callout: Enabled
LDAP Sorting Results: Enabled
```
### GrandStream Phones

```
LDAP Protocol: LDAP
Dirección del servidor: 172.16.1.242
Puerto: 10389
Base: asterisk
Nombre de Usuario: cn-admin,dc=pbx,dc=com
Contraseña: password
LDAP Numero de Filtro: (|(telephoneNumber=%)(homePhone=%))
LDAP Nombre de Filtro: (|(cn=%)(sn=%))
versión LDAP: 3
LDAP Nombre de Atributos: cn sn an
LDAP Numero de Atributos: telephoneNumber homePhone
LDAP Mostar el Nombre: %cn
Max. Visitas: 50
Búsqueda de tiempo de espera: 30
Búsqueda LDAP: check Llamadas Entrantes y Llamadas Salientes
Busqueda de nombre para mostrar: %cn
```
