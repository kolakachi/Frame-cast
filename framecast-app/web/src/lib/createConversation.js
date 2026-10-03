// A preview is an event in the conversation, not a footer after every future message.
export function conversationTimeline(messages = [], revision = null) {
  const events = messages.map(m => ({ ...m, eventType: 'message' }))
  if (revision) events.push({ id: `revision:${revision.id}`, created_at: revision.created_at, eventType: 'revision' })
  const time = value => Date.parse(/(?:Z|[+-]\d\d:\d\d)$/.test(value || '') ? value : String(value || '').replace(' ', 'T') + 'Z') || 0
  return events.sort((a, b) => time(a.created_at) - time(b.created_at))
}
export function acceptConversationResponse(currentVersion, incomingVersion, request, applied) {
  return request >= applied && Number(incomingVersion) >= Number(currentVersion ?? -1)
}
