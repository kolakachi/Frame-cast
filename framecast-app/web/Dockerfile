FROM node:22-alpine AS dev

WORKDIR /app

COPY package.json yarn.lock ./
RUN yarn install --ignore-optional

COPY . .

CMD ["yarn", "dev", "--host", "0.0.0.0", "--port", "5173"]

FROM node:22-alpine AS build

WORKDIR /app

COPY package.json yarn.lock ./
RUN yarn install --ignore-optional

COPY . .
RUN yarn build

FROM nginx:alpine AS production

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
