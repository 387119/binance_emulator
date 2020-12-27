ansible-playbook --vault-password-file=ansible/.ansible_vault_file --extra-vars "redeploy=1" -v deploy.yml -i ansible/hosts --limit cc-bars-srv1
