# tproxy
Some random code that demonstrates how to properly use Netfilters tproxy target together with IP_FREEBIND, IP_TRANSPARENT and IP_ORIGDSTADDR in Linux.

## Usage
1. Set your LAN subnet to 10.22.22.0/24
2. Run the iptables command present in server.php
3. Run `php server.php` as root
