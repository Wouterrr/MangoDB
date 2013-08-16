MongoDB for Kohana 3.x
======================

Mango
-----

Mango is an ORM/ActiveRecord like library that takes full advantage of MongoDB's features. Mango supports:
* **Atomic updates** - the library will calculate what values changed, and will update only those values using atomic modifiers like $set, $push/$pull and $inc
* **All Mongo datatypes** including embedded objects, arrays, enums and (multidimensional) counters
* Relationships you remember from the RDBMS world like has_one, belongs_to, has_many and has_and_belongs_to_many
* Validation of object data, including embedded objects
* Class Table Extension-like behavior - eg Model_Ferrari extends Model_Car extends Mango.
* Very easy syntax very familiar to users of other ORMs

MangoDB
-------

A simple wrapper to access MongoDB. Store your database info in a config file and access MongoDB from anywhere in your code using MangoDB::instance().


Contributors
------------

* [Clayton McIlrath](https://github.com/thinkclay)
* [Daniel Macedo](https://github.com/dm)
* [Henry Simonen](https://github.com/henryfi)
* [Kolo](https://github.com/koroboku)
* [Sergey Yakovlev](https://github.com/sergeyklay)
* [Wouter Wiegmans](https://github.com/Wouterrr)


License
-------

Licensed under [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0)

Copyright © Wouter Wiegmans et al.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this work except in compliance with the License.
You may obtain a copy of the License in the LICENSE file, or at:

   http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.



"THE BEER-WARE LICENSE" (Revision 42):
Wouter Wiegmans wrote this file. As long as you retain this notice you
can do whatever you want with this stuff. If we meet some day, and you think
this stuff is worth it, you can buy me a beer in return - Wouter Wiegmans
