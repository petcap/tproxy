# tproxy
Some random code that demonstrates how to properly use Netfilters tproxy target together with IP_FREEBIND, IP_TRANSPARENT and IP_ORIGDSTADDR in Linux.

## Usage
1. Set your LAN subnet to 10.22.22.0/24
2. Run the iptables command present in server.php
3. Run `php server.php` as root

## Limitations
- Currently only supports TCP. Unfortunately, UDP support cannot be added in PHP because we cannot obtain the C struct containing both the source/destination IPs and ports. This is a limitation of PHP and not Netfilter, an implementation written in C (or perhaps other languages) would be able to also implement UDP support.
