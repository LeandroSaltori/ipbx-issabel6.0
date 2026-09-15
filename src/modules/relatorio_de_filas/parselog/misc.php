<?php
/*
   Copyright 2007, 2020 Nicolás Gudiño

   This file is part of Asternic Call Center Stats.

    Asternic Call Center Stats is free software: you can redistribute it 
    and/or modify it under the terms of the GNU General Public License as 
    published by the Free Software Foundation, either version 3 of the 
    License, or (at your option) any later version.

    Asternic Call Center Stats is distributed in the hope that it will be 
    useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Asternic Call Center Stats.  If not, see 
    <http://www.gnu.org/licenses/>.
*/

function return_timestamp($date_string) {
    list ($year,$month,$day,$hour,$min,$sec) = preg_split("/-|:| /",$date_string,6);
    $u_timestamp = mktime($hour,$min,$sec,$month,$day,$year);
    return $u_timestamp;
}

function check_queue($queue_name) {
    global $queuecache, $midb;

    if($queue_name=="") {
        return 0;
    }

    if(isset($queuecache["$queue_name"])) {
        return $queuecache["$queue_name"];
    }

    $query = "SELECT qname_id,queue FROM qname WHERE queue='$queue_name'";
    $res = $midb->consulta($query,0,0);

    if($midb->num_rows($res)>0) {
        $row = $midb->fetch_row($res);
        return $row[0];
    } else {
        $query = "INSERT INTO qname (queue) VALUES ('$queue_name')";
        $res = $midb->consulta($query,0,0);
        $id = $midb->insert_id($res);
        $queuecache["$queue_name"]=$id;
        return $id;
    }
}

function check_agent($agent) {
    global $agentcache;
    global $argv, $midb, $convertlocal;

    if($agent=="") {
        return 0;
    }

    if($convertlocal) {
        if(preg_match("/^Local\/([^@]*).*/i",$agent,$matches)) {
            $agent = "Agent/".$matches[1];
        }
    }

    if(isset($agentcache["$agent"])) {
        return $agentcache["$agent"];
    }

    $query = "SELECT agent_id,agent FROM qagent WHERE agent='$agent'";
    $res = $midb->consulta($query);

    if($midb->num_rows($res)>0) {
        $row = $midb->fetch_row($res);
        return $row[0];
    } else {
        $query = "INSERT INTO qagent (agent) VALUES ('$agent')";
        $res = $midb->consulta($query,0,0);
        $id = $midb->insert_id($res);
        $agentcache["$agent"]=$id;
        return $id;
    }
}

function check_event($event_name) {
    global $event_array, $midb;

    if ($event_name == "") {
        return 0;
    }

    if (isset($event_array["$event_name"])) {
        return $event_array["$event_name"];
    }

    $query = "SELECT event_id, event FROM qevent WHERE event = '%s'";
    $res = $midb->consulta($query, array($event_name));

    if ($midb->num_rows($res) > 0) {
        $row = $midb->fetch_row($res);
        $event_array["$event_name"] = $row[0];
        return $row[0];
    } else {
        $resMax = $midb->consulta("SELECT COALESCE(MAX(event_id), 0) + 1 FROM qevent");
        $rowMax = $midb->fetch_row($resMax);
        $nextId = intval($rowMax[0]);

        $query = "INSERT IGNORE INTO qevent (event_id, event) VALUES (%d, '%s')";
        $midb->consulta($query, array($nextId, $event_name));
        $event_array["$event_name"] = $nextId;
        return $nextId;
    }
}

function procesa($linea) {
    global $event_array;
    global $last_event_ts;
    global $midb;
    global $processed_count, $inserted_count;

    $linea = trim($linea);
    if ($linea === '') return;

    $partes     = preg_split("/\|/", $linea, 8);
    $date_raw   = trim(array_shift($partes));
    $uniqueid   = trim(array_shift($partes));
    $queue_name = trim(array_shift($partes));
    $agent      = trim(array_shift($partes));
    $event      = trim(array_shift($partes));
    $data1      = count($partes) > 0 ? trim(array_shift($partes)) : '';
    $data2      = count($partes) > 0 ? trim(array_shift($partes)) : '';
    $data3      = count($partes) > 0 ? trim(array_shift($partes)) : '';

    if ($date_raw === '') return;

    // Suporte para timestamps inteiros ou com ponto flutuante (ex: 1726437600 ou 1726437600.123)
    if (is_numeric($date_raw)) {
        $epoch = intval(floatval($date_raw));
    } else {
        $epoch = strtotime($date_raw);
    }

    if (!$epoch || $epoch <= 0) {
        return;
    }

    if ($epoch < $last_event_ts) {
        return;
    }

    $date_formatted = date("Y-m-d H:i:s", $epoch);
    $queue_id = check_queue($queue_name);
    $agent_id = check_agent($agent);
    $event_id = check_event($event);

    if ($agent_id <> -1 && $event_id > 0) {
        $query = "INSERT IGNORE INTO queue_stats (uniqueid, datetime, qname, qagent, qevent, info1, info2, info3) ";
        $query .= "VALUES ('%s','%s','%s','%s','%s','%s','%s','%s')";
        $res = $midb->consulta($query, array($uniqueid, $date_formatted, $queue_id, $agent_id, $event_id, $data1, $data2, $data3));
        if ($res) {
            $inserted_count++;
        }
    }
    $processed_count++;
}
?>
