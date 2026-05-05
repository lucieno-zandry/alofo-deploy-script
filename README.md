Run the container
```
docker run -d \
  -p 12000:80 \
  -u root \
  -v /var/run/docker.sock:/var/run/docker.sock \
  --name webhook \
  lucienozandry/alofo-docker-webhook:latest
```