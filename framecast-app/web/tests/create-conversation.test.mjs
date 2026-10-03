import test from 'node:test'
import assert from 'node:assert/strict'
import { conversationTimeline, acceptConversationResponse } from '../src/lib/createConversation.js'
test('follow-up and plan remain below the earlier video', () => {
 const timeline = conversationTimeline([
  {id:'brief', created_at:'2026-10-02T21:17:20Z'},
  {id:'reply', created_at:'2026-10-02T21:32:49Z'},
  {id:'plan', created_at:'2026-10-02T21:34:17Z'},
 ], {id:'output', created_at:'2026-10-02 21:25:00'})
 assert.deepEqual(timeline.map(e=>e.id), ['brief','revision:output','reply','plan'])
})
test('stale refreshes cannot remove a saved follow-up', () => {
 assert.equal(acceptConversationResponse(8,5,3,2),false)
 assert.equal(acceptConversationResponse(8,8,1,2),false)
 assert.equal(acceptConversationResponse(8,8,3,2),true)
 assert.equal(acceptConversationResponse(null,0,4,3),true)
})
