# Binance emulator
this app created for emulate binance features, that you can test your BOTs, and run it on historical data, finally you can see benefits you can obtain from your bot logic

## links
page with deployed app https://test.defpoint.org/cc/bot1/

## automation

## test host prerequesites
- docker
- docker-compose
- python3
- python3-pip
- disable selinux (worked on this) or install libselinux-python (does not tested)
- pip3 install docker
- pip3 install docker-compose

### add ansible vault password
create file ansible/.ansible_vault_file with content password  
example: run from directory with cloned repository
```
echo "known password" > ansible/.ansible_vault_file
```
> :warning: **git clean -fdx will delete this file, so make a copy**

