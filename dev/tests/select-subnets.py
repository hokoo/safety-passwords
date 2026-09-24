#!/usr/bin/env python3
"""Select two unused IPv4 /24s for the isolated integration Compose project."""

import ipaddress
import json
import subprocess
import sys


POOL = ipaddress.ip_network("10.254.0.0/16")


def command(*args):
    result = subprocess.run(args, check=True, capture_output=True, text=True, timeout=30)
    return result.stdout


def json_list(value):
    parsed = json.loads(value)
    if not isinstance(parsed, list):
        raise ValueError("Expected a JSON array")
    return parsed


def docker_subnets(networks):
    occupied = []
    for network in networks:
        if not isinstance(network, dict):
            raise ValueError("Invalid Docker network")
        driver = network.get("Driver")
        ipam = network.get("IPAM")
        if not isinstance(driver, str) or not isinstance(ipam, dict):
            raise ValueError("Incomplete Docker network")
        config = ipam.get("Config")
        if config is None:
            config = []
        if not isinstance(config, list):
            raise ValueError("Invalid Docker IPAM")
        if not config and driver not in ("host", "null"):
            raise ValueError("Unknown Docker network subnet")
        for item in config:
            if not isinstance(item, dict):
                raise ValueError("Invalid Docker IPAM entry")
            subnet = item.get("Subnet")
            if not isinstance(subnet, str) or not subnet:
                raise ValueError("Unknown Docker IPAM subnet")
            parsed = ipaddress.ip_network(subnet, strict=False)
            if parsed.version == 4:
                occupied.append(parsed)
    return occupied


def route_subnets(routes):
    occupied = []
    for route in routes:
        if not isinstance(route, dict):
            raise ValueError("Invalid host route")
        destination = route.get("dst")
        if destination == "default":
            continue
        if not isinstance(destination, str) or not destination:
            raise ValueError("Unknown host route")
        parsed = ipaddress.ip_network(destination, strict=False)
        if parsed != ipaddress.ip_network("0.0.0.0/0"):
            occupied.append(parsed)
    return occupied


def interface_subnets(interfaces):
    occupied = []
    for interface in interfaces:
        if not isinstance(interface, dict):
            raise ValueError("Invalid host interface")
        addresses = interface.get("addr_info")
        if not isinstance(addresses, list):
            raise ValueError("Unknown host interface addresses")
        for address in addresses:
            if not isinstance(address, dict):
                raise ValueError("Invalid host interface address")
            if address.get("family") != "inet":
                continue
            local = address.get("local")
            prefix = address.get("prefixlen")
            if not isinstance(local, str) or not isinstance(prefix, int):
                raise ValueError("Unknown host interface subnet")
            occupied.append(ipaddress.ip_network("{}/{}".format(local, prefix), strict=False))
    return occupied


def select_subnets(occupied):
    selected = []
    for candidate in POOL.subnets(new_prefix=24):
        if not any(candidate.overlaps(existing) for existing in occupied):
            selected.append(candidate)
            if len(selected) == 2:
                return selected
    raise ValueError("No two free integration subnets")


def main():
    ids = command("docker", "network", "ls", "--quiet").splitlines()
    if not ids:
        raise ValueError("Cannot inspect Docker networks")
    networks = json_list(command("docker", "network", "inspect", *ids))
    if len(networks) != len(ids) or not all(
        any(isinstance(item, dict) and isinstance(item.get("Id"), str)
            and item["Id"].startswith(network_id) for item in networks)
        for network_id in ids
    ):
        raise ValueError("Docker network inventory changed during inspection")
    occupied = docker_subnets(networks)
    occupied += route_subnets(json_list(command("ip", "-j", "-4", "route", "show", "table", "all")))
    occupied += interface_subnets(json_list(command("ip", "-j", "-4", "address", "show")))
    for subnet in select_subnets(occupied):
        print(subnet)


if __name__ == "__main__":
    try:
        main()
    except (OSError, subprocess.SubprocessError, ValueError, ipaddress.AddressValueError, ipaddress.NetmaskValueError):
        print("Cannot prove two integration subnets are unused; refusing to provision.", file=sys.stderr)
        sys.exit(2)
