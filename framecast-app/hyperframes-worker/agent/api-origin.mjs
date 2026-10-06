// The token travels with every request: plain HTTP only to this machine or a private network (the production worker
// reaches the API on the provider's private subnet); anything else must be HTTPS.
export function apiOriginAllowed(u){
 const local=['localhost','127.0.0.1','[::1]'].includes(u.hostname);
 const priv=/^(10\.\d{1,3}\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})$/.test(u.hostname);
 return !u.username&&!u.password&&u.pathname==='/'&&(u.protocol==='https:'||(u.protocol==='http:'&&(local||priv)));
}
